<?php

declare(strict_types=1);

namespace App\Core\Integration;

use GraphQL\Query;
use GraphQL\Variable;
use Psr\Http\Client\ClientInterface;

class GoodsService extends GraphQLService
{
    private const CATEGORY_DEFAULT_FIELDS = [
        'id',
        'name',
        'title',
        'mpath',
        'href',
    ];

    public function __construct(
        string $url,
        string $user,
        string $password,
        int $timeout,
        int $connectTimeout,
        ?ClientInterface $httpClient = null
    ) {
        parent::__construct(
            $url,
            $user,
            $password,
            $timeout,
            $connectTimeout,
            $httpClient
        );
    }

    public function goodsOne(int $id, array $fields = []): array
    {
        $fields = $fields ?: [
            'id',
            'category_id',
            'mpath',
            'price',
            'sell_status',
            'state',
            'status',
        ];

        return $this->one($id, 'goods', $fields);
    }

    public function categoryOne(int $id, array $fields = []): array
    {
        $fields = $fields ?: self::CATEGORY_DEFAULT_FIELDS;

        return $this->one($id, 'category', $fields);
    }

    public function categoryMany(array $ids, array $fields = []): array
    {
        $fields = $fields ?: self::CATEGORY_DEFAULT_FIELDS;

        return $this->many($ids, 'category', $fields);
    }

    public function producerOne(int $id, array $fields = []): array
    {
        $fields = $fields ?: [
            'id',
            'name',
            'title',
        ];

        return $this->one($id, 'producer', $fields);
    }

    protected function one(int $id, string $entity, array $fields = [])
    {
        $fieldName = "{$entity}One";

        $query = (new Query($fieldName))
            ->setVariables([new Variable('where', 'Map!')])
            ->setArguments(['where' => '$where'])
            ->setSelectionSet($fields)
        ;

        return $this->getData($query, ['where' => ['id_eq' => $id]])['data'][$fieldName] ?? [];
    }

    protected function many(
        array $ids,
        string $entity,
        array $fields = [],
        int $size = 1000
    ) {
        $fieldName = "{$entity}Many";

        $query = (new Query($fieldName))
            ->setVariables([
                new Variable('where', 'Map!'),
                new Variable('page', 'Page'),
            ])
            ->setArguments([
                'where' => '$where',
                'page' => '$page',
            ])
            ->setSelectionSet([
                (new Query('nodes'))->setSelectionSet($fields),
            ])
        ;

        return $this->getData($query, [
            'where' => ['id_in' => $ids],
            'page' => ['ID' => 1, 'size' => $size, 'direction' => 'after'],
        ])['data'][$fieldName]['nodes'] ?? [];
    }
}
