<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter;

use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\NodeFactory;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\ProjectionContentGraph;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepository\Core\Factory\ProjectionFactoryDependencies;
use Neos\ContentRepository\Core\Infrastructure\DbalClientInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjection;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\Projections;

/**
 * Use this class as ProjectionFactory in your configuration to construct a content graph
 *
 * @implements ProjectionFactoryInterface<ContentGraphProjection>
 *
 * @api
 */
final class DoctrineDbalContentGraphProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly DbalClientInterface $dbalClient
    ) {
    }

    public static function graphProjectionTableNamePrefix(
        ContentRepositoryId $contentRepositoryId
    ): string {
        return sprintf('cr_%s_p_graph', $contentRepositoryId);
    }

    public function build(
        ProjectionFactoryDependencies $projectionFactoryDependencies,
        array $options,
        CatchUpHookFactoryInterface $catchUpHookFactory,
        Projections $projectionsSoFar
    ): ContentGraphProjection {
        $tableNamePrefix = self::graphProjectionTableNamePrefix(
            $projectionFactoryDependencies->contentRepositoryId
        );

        return new ContentGraphProjection(
            // @phpstan-ignore-next-line
            new DoctrineDbalContentGraphProjection(
                $projectionFactoryDependencies->eventNormalizer,
                $this->dbalClient,
                new NodeFactory(
                    $projectionFactoryDependencies->contentRepositoryId,
                    $projectionFactoryDependencies->nodeTypeManager,
                    $projectionFactoryDependencies->propertyConverter
                ),
                $projectionFactoryDependencies->nodeTypeManager,
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
