<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Postgres;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Platform\Vector\VectorInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\InitializableStoreInterface;
use Symfony\AI\Store\VectorStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Requires PostgreSQL with pgvector extension.
 *
 * @author Simon André <smn.andre@gmail.com>
 *
 * @see https://github.com/pgvector/pgvector
 */
final readonly class Store implements VectorStoreInterface, InitializableStoreInterface
{
    public function __construct(
        private \PDO $connection,
        private string $tableName,
        private string $vectorFieldName = 'embedding',
    ) {
    }

    public static function fromPdo(\PDO $connection, string $tableName, string $vectorFieldName = 'embedding'): self
    {
        return new self($connection, $tableName, $vectorFieldName);
    }

    public static function fromDbal(Connection $connection, string $tableName, string $vectorFieldName = 'embedding'): self
    {
        $pdo = $connection->getNativeConnection();

        if (!$pdo instanceof \PDO) {
            throw new InvalidArgumentException('Only DBAL connections using PDO driver are supported.');
        }

        return self::fromPdo($pdo, $tableName, $vectorFieldName);
    }

    public function add(VectorDocument ...$documents): void
    {
        $statement = $this->connection->prepare(
            \sprintf(
                'INSERT INTO %1$s (id, metadata, %2$s)
                VALUES (:id, :metadata, :vector)
                ON CONFLICT (id) DO UPDATE SET metadata = EXCLUDED.metadata, %2$s = EXCLUDED.%2$s',
                $this->tableName,
                $this->vectorFieldName,
            ),
        );

        foreach ($documents as $document) {
            $operation = [
                'id' => $document->id->toRfc4122(),
                'metadata' => json_encode($document->metadata->getArrayCopy(), \JSON_THROW_ON_ERROR),
                'vector' => $this->toPgvector($document->vector),
            ];

            $statement->execute($operation);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param float|null           $minScore Minimum score to filter results (optional)
     *
     * @return VectorDocument[]
     */
    public function query(Vector $vector, array $options = [], ?float $minScore = null): array
    {
        $sql = \sprintf(
            'SELECT id, %s AS embedding, metadata, (%s <-> :embedding) AS score
             FROM %s
             %s
             ORDER BY score ASC
             LIMIT %d',
            $this->vectorFieldName,
            $this->vectorFieldName,
            $this->tableName,
            null !== $minScore ? "WHERE ({$this->vectorFieldName} <-> :embedding) >= :minScore" : '',
            $options['limit'] ?? 5,
        );
        $statement = $this->connection->prepare($sql);

        $params = [
            'embedding' => $this->toPgvector($vector),
        ];
        if (null !== $minScore) {
            $params['minScore'] = $minScore;
        }

        $statement->execute($params);

        $documents = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $result) {
            $documents[] = new VectorDocument(
                id: Uuid::fromString($result['id']),
                vector: new Vector($this->fromPgvector($result['embedding'])),
                metadata: new Metadata(json_decode($result['metadata'] ?? '{}', true, 512, \JSON_THROW_ON_ERROR)),
                score: $result['score'],
            );
        }

        return $documents;
    }

    public function initialize(array $options = []): void
    {
        if ([] !== $options) {
            throw new InvalidArgumentException('No supported options');
        }

        $this->connection->exec('CREATE EXTENSION IF NOT EXISTS vector');

        $this->connection->exec(
            \sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id UUID PRIMARY KEY,
                    metadata JSONB,
                    %s vector(1536) NOT NULL
                )',
                $this->tableName,
                $this->vectorFieldName,
            ),
        );
        $this->connection->exec(
            \sprintf(
                'CREATE INDEX IF NOT EXISTS %s_%s_idx ON %s USING ivfflat (%s vector_cosine_ops)',
                $this->tableName,
                $this->vectorFieldName,
                $this->tableName,
                $this->vectorFieldName,
            ),
        );
    }

    private function toPgvector(VectorInterface $vector): string
    {
        return '['.implode(',', $vector->getData()).']';
    }

    /**
     * @return float[]
     */
    private function fromPgvector(string $vector): array
    {
        return json_decode($vector, true, 512, \JSON_THROW_ON_ERROR);
    }
}
