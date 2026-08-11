<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\ChromaDb;

use Codewithkyrian\ChromaDB\Client;
use Codewithkyrian\ChromaDB\Embeddings\EmbeddingFunction;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * @author Guillaume Loulier <personal@guillaumeloulier.fr>
 */
final class StoreFactory
{
    public static function create(
        Client $client,
        string $collectionName,
        ?EmbeddingFunction $embeddingFunction = null,
    ): ManagedStoreInterface&StoreInterface {
        return new Store($client, $collectionName, $embeddingFunction);
    }
}
