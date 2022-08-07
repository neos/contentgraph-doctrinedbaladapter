<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter;

use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\NodeFactory;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\ProjectionContentGraph;
use Neos\ContentRepository\Factory\ContentRepositoryIdentifier;
use Neos\ContentRepository\Factory\ProjectionFactoryDependencies;
use Neos\ContentRepository\Infrastructure\DbalClientInterface;
use Neos\ContentRepository\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Projection\ContentGraph\ContentGraphProjection;
use Neos\ContentRepository\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Projection\ProjectionInterface;
use Neos\ContentRepository\Projection\Projections;

/**
 * @implements ProjectionFactoryInterface<ContentGraphProjection>
 */
final class DoctrineDbalContentGraphProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly DbalClientInterface $dbalClient
    ) {
    }

    public static function graphProjectionTableNamePrefix(
        ContentRepositoryIdentifier $contentRepositoryIdentifier
    ): string {
        return sprintf('neos_cr_%s_projection_graph', $contentRepositoryIdentifier);
    }

    public function build(
        ProjectionFactoryDependencies $projectionFactoryDependencies,
        array $options,
        CatchUpHookFactoryInterface $catchUpHookFactory,
        Projections $projectionsSoFar
    ): ContentGraphProjection {
        $tableNamePrefix = self::graphProjectionTableNamePrefix(
            $projectionFactoryDependencies->contentRepositoryIdentifier
        );

        return new ContentGraphProjection(
            // @phpstan-ignore-next-line
            new DoctrineDbalContentGraphProjection(
                $projectionFactoryDependencies->eventNormalizer,
                $this->dbalClient,
                new NodeFactory(
                    $projectionFactoryDependencies->contentRepositoryIdentifier,
                    $projectionFactoryDependencies->nodeTypeManager,
                    $projectionFactoryDependencies->propertyConverter
                ),
                new ProjectionContentGraph(
                    $this->dbalClient,
                    $tableNamePrefix
                ),
                $catchUpHookFactory,
                $tableNamePrefix
            )
        );
    }
}