<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Cloudflare;

use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Guillaume Loulier <personal@guillaumeloulier.fr>
 */
final class Store implements ManagedStoreInterface, StoreInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $index,
        private readonly int $dimensions = 1536,
        private readonly string $metric = 'cosine',
    ) {
    }

    public function setup(array $options = []): void
    {
        if ([] !== $options) {
            throw new InvalidArgumentException('No supported options.');
        }

        $this->request('POST', 'vectorize/v2/indexes', [
            'config' => [
                'dimensions' => $this->dimensions,
                'metric' => $this->metric,
            ],
            'name' => $this->index,
        ]);
    }

    public function drop(array $options = []): void
    {
        $this->request('DELETE', \sprintf('vectorize/v2/indexes/%s', $this->index));
    }

    public function add(VectorDocument|array $documents): void
    {
        if ($documents instanceof VectorDocument) {
            $documents = [$documents];
        }

        $payload = array_map(
            $this->convertToIndexableArray(...),
            $documents,
        );

        $this->request('POST', \sprintf('vectorize/v2/indexes/%s/upsert', $this->index), static function () use ($payload) {
            foreach ($payload as $entry) {
                yield json_encode($entry).\PHP_EOL;
            }
        });
    }

    public function remove(string|array $ids, array $options = []): void
    {
        if (\is_string($ids)) {
            $ids = [$ids];
        }

        $this->request('POST', \sprintf('vectorize/v2/indexes/%s/delete_by_ids', $this->index), [
            'ids' => $ids,
        ]);
    }

    public function supports(string $queryClass): bool
    {
        return VectorQuery::class === $queryClass;
    }

    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof VectorQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }

        $vector = $query->getVector();
        $results = $this->request('POST', \sprintf('vectorize/v2/indexes/%s/query', $this->index), [
            'vector' => $vector->getData(),
            'returnValues' => true,
            'returnMetadata' => 'all',
        ]);

        $result = $results['result'] ?? null;
        if (!\is_array($result)) {
            throw new RuntimeException('The Cloudflare search response is malformed.');
        }

        $matches = $result['matches'] ?? null;
        if (!\is_array($matches)) {
            throw new RuntimeException('The Cloudflare search response does not contain a result set.');
        }

        foreach ($matches as $item) {
            if (!\is_array($item)) {
                throw new RuntimeException('The Cloudflare search response contains an invalid match.');
            }

            yield $this->convertToVectorDocument($item);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function request(string $method, string $endpoint, \Closure|array $payload = []): array
    {
        $options = [];

        if ($payload instanceof \Closure) {
            $options['headers'] = [
                'Content-Type' => 'application/x-ndjson',
            ];

            $options['body'] = $payload();
        }

        if (\is_array($payload)) {
            $options['json'] = $payload;
        }

        $response = $this->httpClient->request($method, $endpoint, $options);

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function convertToIndexableArray(VectorDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'values' => $document->getVector()->getData(),
            'metadata' => $document->getMetadata()->getArrayCopy(),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private function convertToVectorDocument(array $data): VectorDocument
    {
        $id = $data['id'] ?? throw new InvalidArgumentException('Missing "id" field in the document data.');
        if (!\is_string($id) && !\is_int($id)) {
            throw new InvalidArgumentException('The document "id" field must be a string or an integer.');
        }

        $rawVector = $data['values'] ?? null;
        if (null === $rawVector) {
            $vector = new NullVector();
        } else {
            if (!\is_array($rawVector)) {
                throw new InvalidArgumentException('The document vector must be an array of numbers.');
            }

            $components = [];
            foreach ($rawVector as $component) {
                if (!\is_int($component) && !\is_float($component)) {
                    throw new InvalidArgumentException('The document vector must contain only numbers.');
                }

                $components[] = (float) $component;
            }

            $vector = new Vector($components);
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!\is_array($rawMetadata)) {
            throw new InvalidArgumentException('The document metadata must be an array.');
        }

        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('The document metadata must be keyed by strings.');
            }

            $metadata[$key] = $value;
        }

        $score = $data['score'] ?? null;
        if (null !== $score && !\is_int($score) && !\is_float($score)) {
            throw new InvalidArgumentException('The document "score" field must be a number.');
        }

        return new VectorDocument(
            id: $id,
            vector: $vector,
            metadata: new Metadata($metadata),
            score: null === $score ? null : (float) $score,
        );
    }
}
