<?php

declare(strict_types=1);

namespace PhpstanContracts\Tests\Fixtures;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Pure;

/**
 * What the prover says about the classes of a data framework application: a data class whose
 * properties are all of a type that is not supported, a model that is not a data class, and a
 * data class where one property is supported and another is not.
 */
final class DataDiagnostics
{
    #[Pure]
    public static function takesADataClassWithNoSupportedProperty(LoadedPricing $data): int
    {
        return 1;
    }

    #[Pure]
    public static function takesAModel(ModelLike $model): int
    {
        return 1;
    }

    #[Pure]
    public static function takesADataClassOfModelsOnly(ModelsOnly $data): int
    {
        return 1;
    }

    #[Ensures('$result === ($data->placedAt > $start)')]
    public static function readsTheCountOfADataClassWithAModel(PricingWithModel $data, int $start): bool
    {
        return $data->placedAt > $start;
    }

    #[Pure]
    public static function takesADate(\DateTimeImmutable $date): int
    {
        return 1;
    }

    #[Pure]
    public static function readsTheModelOfADataClass(PricingWithModel $data): bool
    {
        return $data->customer === null;
    }
}

/**
 * An Eloquent model: mutable state held in protected properties.
 */
class ModelLike
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    public bool $exists = false;
}

/**
 * An Eloquent collection: the items are a protected array.
 */
class CollectionLike
{
    /** @var array<int, ModelLike> */
    protected array $items = [];
}

/**
 * Shaped like the DTO of a data framework that holds loaded models: every public readonly
 * property has a type that is not supported, and the inherited state is not public.
 */
final class LoadedPricing extends FrameworkData
{
    public function __construct(
        public readonly ?ModelLike $customer,
        public readonly ModelLike $product,
        public readonly CollectionLike $lines,
    ) {
    }
}

final class ModelsOnly
{
    public function __construct(
        public readonly ModelLike $customer,
        public readonly ModelLike $product,
        public readonly ModelLike $a,
        public readonly ModelLike $b,
        public readonly ModelLike $c,
        public readonly ModelLike $d,
        public int $count = 0,
    ) {
    }
}

final class PricingWithModel extends FrameworkData
{
    public function __construct(
        public readonly int $placedAt,
        public readonly ?ModelLike $customer,
    ) {
    }
}
