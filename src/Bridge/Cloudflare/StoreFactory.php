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

use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Guillaume Loulier <personal@guillaumeloulier.fr>
 */
final class StoreFactory
{
    public static function create(
        string $index,
        ?string $accountId = null,
        #[\SensitiveParameter] ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
        int $dimensions = 1536,
        string $metric = 'cosine',
        string $endpoint = 'https://api.cloudflare.com/client/v4/accounts',
    ): StoreInterface&ManagedStoreInterface {
        $httpClient ??= HttpClient::create();

        if (null !== $accountId) {
            $defaultOptions = [];
            if (null !== $apiKey) {
                $defaultOptions['auth_bearer'] = $apiKey;
            }

            $httpClient = ScopingHttpClient::forBaseUri(
                $httpClient,
                \sprintf('%s/%s/', $endpoint, $accountId),
                $defaultOptions,
            );
        }

        return new Store($httpClient, $index, $dimensions, $metric);
    }
}
