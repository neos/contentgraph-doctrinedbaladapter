<?php
declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection;

/*
 * This file is part of the Neos.ContentGraph.DoctrineDbalAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\DBAL\Connection;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\ProjectionContentGraph;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeAggregateWasRemoved;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodesWereRemovedFromAggregate;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event;
use Neos\EventSourcedContentRepository\Domain as ContentRepository;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodePropertiesWereSet;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeWasHidden;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeWasShown;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeGeneralizationVariantWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeSpecializationVariantWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodesWereMoved;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\NodeAggregateClassification;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\ContentRepository\Domain\NodeType\NodeTypeName;
use Neos\EventSourcedContentRepository\Domain\ValueObject\PropertyValues;
use Neos\EventSourcedContentRepository\Service\Infrastructure\Service\DbalClient;
use Neos\EventSourcing\Event\Decorator\DomainEventWithIdentifierInterface;
use Neos\EventSourcing\Event\DomainEvents;
use Neos\EventSourcing\EventListener\AfterInvokeInterface;
use Neos\EventSourcing\EventStore\EventEnvelope;
use Neos\EventSourcing\Projection\ProjectorInterface;
use Neos\Flow\Annotations as Flow;

/**
 * The alternate reality-aware graph projector for the Doctrine backend
 *
 * @Flow\Scope("singleton")
 */
class GraphProjector implements ProjectorInterface, AfterInvokeInterface
{
    const RELATION_DEFAULT_OFFSET = 128;

    /**
     * @Flow\Inject
     * @var ProjectionContentGraph
     */
    protected $projectionContentGraph;

    /**
     * @Flow\Inject
     * @var DbalClient
     */
    protected $client;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $processedEventsCache;

    public function hasProcessed(DomainEvents $events): bool
    {
        foreach ($events as $event) {
            if (!$event instanceof DomainEventWithIdentifierInterface) {
                throw new \RuntimeException(sprintf('The CommandResult contains an event "%s" that does not implement the %s interface', get_class($event), DomainEventWithIdentifierInterface::class), 1550314769);
            }
            if (!$this->processedEventsCache->has(md5($event->getIdentifier()))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @Flow\Signal
     */
    public function emitProjectionUpdated()
    {
    }

    /**
     * @throws \Throwable
     */
    public function reset(): void
    {
        $this->getDatabaseConnection()->transactional(function () {
            $this->getDatabaseConnection()->executeQuery('TRUNCATE table neos_contentgraph_node');
            $this->getDatabaseConnection()->executeQuery('TRUNCATE table neos_contentgraph_hierarchyrelation');
            $this->getDatabaseConnection()->executeQuery('TRUNCATE table neos_contentgraph_referencerelation');
        });
        $this->processedEventsCache->flush();
    }

    /**
     * @return bool
     * @throws \Doctrine\DBAL\DBALException
     */
    public function isEmpty(): bool
    {
        return $this->projectionContentGraph->isEmpty();
    }

    /**
     * @param RootNodeAggregateWithNodeWasCreated $event
     * @throws \Throwable
     */
    final public function whenRootNodeAggregateWithNodeWasCreated(RootNodeAggregateWithNodeWasCreated $event)
    {
        $nodeRelationAnchorPoint = NodeRelationAnchorPoint::create();
        $dimensionSpacePoint = new DimensionSpacePoint([]);
        $node = new NodeRecord(
            $nodeRelationAnchorPoint,
            $event->getNodeAggregateIdentifier(),
            $dimensionSpacePoint->getCoordinates(),
            $dimensionSpacePoint->getHash(),
            [],
            $event->getNodeTypeName(),
            $event->getNodeAggregateClassification()
        );

        $this->transactional(function () use ($node, $event) {
            $node->addToDatabase($this->getDatabaseConnection());
            $this->connectHierarchy(
                $event->getContentStreamIdentifier(),
                NodeRelationAnchorPoint::forRootEdge(),
                $node->relationAnchorPoint,
                $event->getVisibleInDimensionSpacePoints(),
                null
            );
        });
    }

    /**
     * @param Event\NodeAggregateWithNodeWasCreated $event
     * @throws \Throwable
     */
    final public function whenNodeAggregateWithNodeWasCreated(Event\NodeAggregateWithNodeWasCreated $event)
    {
        $this->transactional(function () use ($event) {
            $this->createNodeWithHierarchy(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getNodeTypeName(),
                $event->getParentNodeAggregateIdentifier(),
                $event->getOriginDimensionSpacePoint(),
                $event->getVisibleInDimensionSpacePoints(),
                $event->getInitialPropertyValues(),
                $event->getNodeAggregateClassification(),
                $event->getSucceedingNodeAggregateIdentifier(),
                $event->getNodeName()
            );

            $this->connectRestrictionEdgesFromParentNodeToNewlyCreatedNode(
                $event->getContentStreamIdentifier(),
                $event->getParentNodeAggregateIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getVisibleInDimensionSpacePoints()
            );
        });
    }

    /**
     * @param Event\NodeAggregateNameWasChanged $event
     * @throws \Throwable
     */
    final public function whenNodeAggregateNameWasChanged(Event\NodeAggregateNameWasChanged $event)
    {
        $this->transactional(function () use ($event) {
            $this->getDatabaseConnection()->executeUpdate('
                UPDATE neos_contentgraph_hierarchyrelation h
                inner join neos_contentgraph_node n on
                    h.childnodeanchor = n.relationanchorpoint
                SET
                  h.name = :newName
                WHERE
                    n.nodeaggregateidentifier = :nodeAggregateIdentifier
                    and h.contentstreamidentifier = :contentStreamIdentifier
            ', [
                'newName' => (string)$event->getNewNodeName(),
                'nodeAggregateIdentifier' => (string)$event->getNodeAggregateIdentifier(),
                'contentStreamIdentifier' => (string)$event->getContentStreamIdentifier()
            ]);
        });
    }

    /**
     * Copy the restriction edges from the parent Node to the newly created child node;
     * so that newly created nodes inherit the visibility constraints of the parent.
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $parentNodeAggregateIdentifier
     * @param NodeAggregateIdentifier $newlyCreatedNodeAggregateIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePointsInWhichNewlyCreatedNodeAggregateIsVisible
     * @throws \Doctrine\DBAL\DBALException
     */
    private function connectRestrictionEdgesFromParentNodeToNewlyCreatedNode(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $parentNodeAggregateIdentifier,
        NodeAggregateIdentifier $newlyCreatedNodeAggregateIdentifier,
        DimensionSpacePointSet $dimensionSpacePointsInWhichNewlyCreatedNodeAggregateIsVisible
    ) {
        $this->getDatabaseConnection()->executeUpdate('
                INSERT INTO neos_contentgraph_restrictionedge (
                  contentstreamidentifier,
                  dimensionspacepointhash,
                  originnodeaggregateidentifier,
                  affectednodeaggregateidentifier
                )
                SELECT
                  r.contentstreamidentifier,
                  r.dimensionspacepointhash,
                  r.originnodeaggregateidentifier,
                  "' . $newlyCreatedNodeAggregateIdentifier . '" as affectednodeaggregateidentifier
                FROM
                    neos_contentgraph_restrictionedge r
                    WHERE 
                        r.contentstreamidentifier = :sourceContentStreamIdentifier
                        and r.dimensionspacepointhash IN (:visibleDimensionSpacePoints)
                        and r.affectednodeaggregateidentifier = :parentNodeAggregateIdentifier
            ', [
            'sourceContentStreamIdentifier' => (string)$contentStreamIdentifier,
            'visibleDimensionSpacePoints' => $dimensionSpacePointsInWhichNewlyCreatedNodeAggregateIsVisible->getPointHashes(),
            'parentNodeAggregateIdentifier' => (string)$parentNodeAggregateIdentifier
        ], [
            'visibleDimensionSpacePoints' => Connection::PARAM_STR_ARRAY
        ]);
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param NodeTypeName $nodeTypeName
     * @param NodeAggregateIdentifier $parentNodeAggregateIdentifier
     * @param DimensionSpacePoint $originDimensionSpacePoint
     * @param DimensionSpacePointSet $visibleInDimensionSpacePoints
     * @param PropertyValues $propertyDefaultValuesAndTypes
     * @param NodeAggregateClassification $nodeAggregateClassification
     * @param NodeAggregateIdentifier|null $succeedingSiblingNodeAggregateIdentifier
     * @param NodeName $nodeName
     * @throws \Doctrine\DBAL\DBALException
     */
    private function createNodeWithHierarchy(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        NodeTypeName $nodeTypeName,
        NodeAggregateIdentifier $parentNodeAggregateIdentifier,
        DimensionSpacePoint $originDimensionSpacePoint,
        DimensionSpacePointSet $visibleInDimensionSpacePoints,
        PropertyValues $propertyDefaultValuesAndTypes,
        NodeAggregateClassification $nodeAggregateClassification,
        NodeAggregateIdentifier $succeedingSiblingNodeAggregateIdentifier = null,
        NodeName $nodeName = null
    ): void {
        $nodeRelationAnchorPoint = NodeRelationAnchorPoint::create();
        $node = new NodeRecord(
            $nodeRelationAnchorPoint,
            $nodeAggregateIdentifier,
            $originDimensionSpacePoint->jsonSerialize(),
            $originDimensionSpacePoint->getHash(),
            $propertyDefaultValuesAndTypes->getPlainValues(),
            $nodeTypeName,
            $nodeAggregateClassification,
            $nodeName
        );

        // reconnect parent relations
        $missingParentRelations = $visibleInDimensionSpacePoints->getPoints();
        $existingParentRelations = $this->projectionContentGraph->findInboundHierarchyRelationsForNodeAggregate(
            $contentStreamIdentifier,
            $nodeAggregateIdentifier,
            $visibleInDimensionSpacePoints
        );
        foreach ($existingParentRelations as $existingParentRelation) {
            $existingParentRelation->assignNewChildNode($nodeRelationAnchorPoint, $this->getDatabaseConnection());
            unset($missingParentRelations[$existingParentRelation->dimensionSpacePointHash]);
        }

        if (!empty($missingParentRelations)) {
            // add yet missing parent relations

            foreach ($missingParentRelations as $dimensionSpacePoint) {
                $parentNode = $this->projectionContentGraph->getNodeInAggregate(
                    $contentStreamIdentifier,
                    $parentNodeAggregateIdentifier,
                    $dimensionSpacePoint
                );

                $succeedingSibling = $succeedingSiblingNodeAggregateIdentifier
                    ? $this->projectionContentGraph->getNodeInAggregate(
                        $contentStreamIdentifier,
                        $succeedingSiblingNodeAggregateIdentifier,
                        $dimensionSpacePoint
                    )
                    : null;

                if ($parentNode) {
                    $this->connectHierarchy(
                        $contentStreamIdentifier,
                        $parentNode->relationAnchorPoint,
                        $nodeRelationAnchorPoint,
                        new DimensionSpacePointSet([$dimensionSpacePoint]),
                        $succeedingSibling ? $succeedingSibling->relationAnchorPoint : null,
                        $nodeName
                    );
                }
            }
        }

        // reconnect child relations
        $existingChildRelations = $this->projectionContentGraph->findOutboundHierarchyRelationsForNodeAggregate(
            $contentStreamIdentifier,
            $nodeAggregateIdentifier,
            $visibleInDimensionSpacePoints
        );
        foreach ($existingChildRelations as $existingChildRelation) {
            $existingChildRelation->assignNewParentNode($nodeRelationAnchorPoint, null, $this->getDatabaseConnection());
        }

        $node->addToDatabase($this->getDatabaseConnection());
    }

    /**
     * @param NodeRelationAnchorPoint $parentNodeAnchorPoint
     * @param NodeRelationAnchorPoint $childNodeAnchorPoint
     * @param NodeRelationAnchorPoint|null $succeedingSiblingNodeAnchorPoint
     * @param NodeName|null $relationName
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePointSet
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function connectHierarchy(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeRelationAnchorPoint $parentNodeAnchorPoint,
        NodeRelationAnchorPoint $childNodeAnchorPoint,
        DimensionSpacePointSet $dimensionSpacePointSet,
        ?NodeRelationAnchorPoint $succeedingSiblingNodeAnchorPoint,
        NodeName $relationName = null
    ): void {
        foreach ($dimensionSpacePointSet->getPoints() as $dimensionSpacePoint) {
            $position = $this->getRelationPosition(
                $parentNodeAnchorPoint,
                null,
                $succeedingSiblingNodeAnchorPoint,
                $contentStreamIdentifier,
                $dimensionSpacePoint
            );

            $hierarchyRelation = new HierarchyRelation(
                $parentNodeAnchorPoint,
                $childNodeAnchorPoint,
                $relationName,
                $contentStreamIdentifier,
                $dimensionSpacePoint,
                $dimensionSpacePoint->getHash(),
                $position
            );

            $hierarchyRelation->addToDatabase($this->getDatabaseConnection());
        }
    }

    /**
     * @param NodeRelationAnchorPoint|null $parentAnchorPoint
     * @param NodeRelationAnchorPoint|null $childAnchorPoint
     * @param NodeRelationAnchorPoint|null $succeedingSiblingAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return int
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getRelationPosition(
        ?NodeRelationAnchorPoint $parentAnchorPoint,
        ?NodeRelationAnchorPoint $childAnchorPoint,
        ?NodeRelationAnchorPoint $succeedingSiblingAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint
    ): int {
        $position = $this->projectionContentGraph->determineHierarchyRelationPosition($parentAnchorPoint, $childAnchorPoint, $succeedingSiblingAnchorPoint, $contentStreamIdentifier, $dimensionSpacePoint);

        if ($position % 2 !== 0) {
            $position = $this->getRelationPositionAfterRecalculation($parentAnchorPoint, $childAnchorPoint, $succeedingSiblingAnchorPoint, $contentStreamIdentifier, $dimensionSpacePoint);
        }

        return $position;
    }

    /**
     * @param NodeRelationAnchorPoint|null $parentAnchorPoint
     * @param NodeRelationAnchorPoint|null $childAnchorPoint
     * @param NodeRelationAnchorPoint|null $succeedingSiblingAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return int
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getRelationPositionAfterRecalculation(
        ?NodeRelationAnchorPoint $parentAnchorPoint,
        ?NodeRelationAnchorPoint $childAnchorPoint,
        ?NodeRelationAnchorPoint $succeedingSiblingAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint
    ): int {
        if (!$childAnchorPoint && !$parentAnchorPoint) {
            throw new \InvalidArgumentException('You must either specify a parent or child node anchor to get relation positions after recalculation.', 1519847858);
        }
        $offset = 0;
        $position = 0;
        $hierarchyRelations = $parentAnchorPoint
            ? $this->projectionContentGraph->getOutboundHierarchyRelationsForNodeAndSubgraph($parentAnchorPoint, $contentStreamIdentifier, $dimensionSpacePoint)
            : $this->projectionContentGraph->getInboundHierarchyRelationsForNodeAndSubgraph($childAnchorPoint, $contentStreamIdentifier, $dimensionSpacePoint);

        foreach ($hierarchyRelations as $relation) {
            $offset += self::RELATION_DEFAULT_OFFSET;
            if ($succeedingSiblingAnchorPoint && $relation->childNodeAnchor === (string)$succeedingSiblingAnchorPoint) {
                $position = $offset;
                $offset += self::RELATION_DEFAULT_OFFSET;
            }
            $relation->assignNewPosition($offset, $this->getDatabaseConnection());
        }

        return $position;
    }

    /**
     * @param ContentRepository\Context\ContentStream\Event\ContentStreamWasForked $event
     * @throws \Throwable
     */
    public function whenContentStreamWasForked(ContentRepository\Context\ContentStream\Event\ContentStreamWasForked $event)
    {
        $this->transactional(function () use ($event) {

            //
            // 1) Copy HIERARCHY RELATIONS (this is the MAIN OPERATION here)
            //
            $this->getDatabaseConnection()->executeUpdate('
                INSERT INTO neos_contentgraph_hierarchyrelation (
                  parentnodeanchor,
                  childnodeanchor,
                  `name`,
                  position,
                  dimensionspacepoint,
                  dimensionspacepointhash,
                  contentstreamidentifier
                )
                SELECT
                  h.parentnodeanchor,
                  h.childnodeanchor, 
                  h.name,
                  h.position,
                  h.dimensionspacepoint,
                  h.dimensionspacepointhash, 
                  "' . (string)$event->getContentStreamIdentifier() . '" AS contentstreamidentifier
                FROM
                    neos_contentgraph_hierarchyrelation h
                    WHERE h.contentstreamidentifier = :sourceContentStreamIdentifier
            ', [
                'sourceContentStreamIdentifier' => (string)$event->getSourceContentStreamIdentifier()
            ]);

            //
            // 2) copy Hidden Node information to second content stream
            //
            $this->getDatabaseConnection()->executeUpdate('
                INSERT INTO neos_contentgraph_restrictionedge (
                  contentstreamidentifier,
                  dimensionspacepointhash,
                  originnodeaggregateidentifier,
                  affectednodeaggregateidentifier
                )
                SELECT
                  "' . (string)$event->getContentStreamIdentifier() . '" AS contentstreamidentifier,
                  r.dimensionspacepointhash,
                  r.originnodeaggregateidentifier,
                  r.affectednodeaggregateidentifier 
                FROM
                    neos_contentgraph_restrictionedge r
                    WHERE r.contentstreamidentifier = :sourceContentStreamIdentifier
            ', [
                'sourceContentStreamIdentifier' => (string)$event->getSourceContentStreamIdentifier()
            ]);
        });
    }

    /**
     * @param NodePropertiesWereSet $event
     * @throws \Throwable
     */
    public function whenNodePropertiesWereSet(NodePropertiesWereSet $event)
    {
        $this->transactional(function () use ($event) {
            $this->updateNodeWithCopyOnWrite($event, function (NodeRecord $node) use ($event) {
                foreach ($event->getPropertyValues() as $propertyName => $propertyValue) {
                    $node->properties[$propertyName] = $propertyValue->getValue();
                }
            });
        });
    }

    /**
     * @param NodeReferencesWereSet $event
     * @throws \Throwable
     */
    public function whenNodeReferencesWereSet(NodeReferencesWereSet $event)
    {
        $this->transactional(function () use ($event) {
            $this->updateNodeWithCopyOnWrite($event, function (NodeRecord $node) use ($event) {
            });

            $nodeAnchorPoint = $this->projectionContentGraph->getAnchorPointForNodeAndOriginDimensionSpacePointAndContentStream(
                $event->getSourceNodeAggregateIdentifier(),
                $event->getSourceOriginDimensionSpacePoint(),
                $event->getContentStreamIdentifier()
            );

            // remove old
            $this->getDatabaseConnection()->delete('neos_contentgraph_referencerelation', [
                'nodeanchorpoint' => $nodeAnchorPoint,
                'name' => $event->getReferenceName()
            ]);

            // set new
            foreach ($event->getDestinationNodeAggregateIdentifiers() as $position => $destinationNodeIdentifier) {
                $this->getDatabaseConnection()->insert('neos_contentgraph_referencerelation', [
                    'name' => $event->getReferenceName(),
                    'position' => $position,
                    'nodeanchorpoint' => $nodeAnchorPoint,
                    'destinationnodeaggregateidentifier' => $destinationNodeIdentifier,
                ]);
            }
        });
    }

    /**
     * @param NodeWasHidden $event
     * @throws \Throwable
     */
    public function whenNodeWasHidden(NodeWasHidden $event)
    {
        $this->transactional(function () use ($event) {
            $this->getDatabaseConnection()->executeUpdate('
-- GraphProjector::whenNodeWasHidden
insert into neos_contentgraph_restrictionedge
(
    -- we build a recursive tree
    with recursive tree as (
         -- --------------------------------
         -- INITIAL query: select the root nodes of the tree; as given in $menuLevelNodeIdentifiers
         -- --------------------------------
         select
            n.relationanchorpoint,
            n.nodeaggregateidentifier,
            h.dimensionspacepointhash
         from
            neos_contentgraph_node n
         -- we need to join with the hierarchy relation, because we need the dimensionspacepointhash.
         inner join neos_contentgraph_hierarchyrelation h
            on h.childnodeanchor = n.relationanchorpoint
         where
            n.nodeaggregateidentifier = :entryNodeAggregateIdentifier
            and h.contentstreamidentifier = :contentStreamIdentifier
            and h.dimensionspacepointhash in (:dimensionSpacePointHashes)
    union
         -- --------------------------------
         -- RECURSIVE query: do one "child" query step
         -- --------------------------------
         select
            c.relationanchorpoint,
            c.nodeaggregateidentifier,
            h.dimensionspacepointhash
         from
            tree p
         inner join neos_contentgraph_hierarchyrelation h
            on h.parentnodeanchor = p.relationanchorpoint
         inner join neos_contentgraph_node c
            on h.childnodeanchor = c.relationanchorpoint
         where
            h.contentstreamidentifier = :contentStreamIdentifier
            and h.dimensionspacepointhash in (:dimensionSpacePointHashes)
    )

    select
        "' . (string)$event->getContentStreamIdentifier() . '" as contentstreamidentifier,
        dimensionspacepointhash,
        "' . (string)$event->getNodeAggregateIdentifier() . '" as originnodeidentifier,
        nodeaggregateidentifier as affectednodeaggregateidentifier
    from tree
)
            ',
                [
                    'entryNodeAggregateIdentifier' => (string)$event->getNodeAggregateIdentifier(),
                    'contentStreamIdentifier' => (string)$event->getContentStreamIdentifier(),
                    'dimensionSpacePointHashes' => $event->getAffectedDimensionSpacePoints()->getPointHashes()
                ],
            [
                'dimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
            ]);
        });
    }

    /**
     * @param NodeWasShown $event
     * @throws \Throwable
     */
    public function whenNodeWasShown(NodeWasShown $event)
    {
        $this->transactional(function () use ($event) {
            $this->removeOutgoingRestrictionEdgesOfNodeAggregateInDimensionSpacePoints($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getAffectedDimensionSpacePoints());
        });
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePointSet $affectedDimensionSpacePoints
     * @throws \Doctrine\DBAL\DBALException
     */
    private function removeOutgoingRestrictionEdgesOfNodeAggregateInDimensionSpacePoints(ContentStreamIdentifier $contentStreamIdentifier, NodeAggregateIdentifier $nodeAggregateIdentifier, DimensionSpacePointSet $affectedDimensionSpacePoints)
    {
        $this->getDatabaseConnection()->executeUpdate('
                -- GraphProjector::removeRestrictionEdgesUnderneathNodeAggregateAndDimensionSpacePoints
 
                delete r.* from
                    neos_contentgraph_restrictionedge r
                    join 
                     (
                        -- we build a recursive tree
                        with recursive tree as (
                             -- --------------------------------
                             -- INITIAL query: select the root nodes of the tree
                             -- --------------------------------
                             select
                                n.relationanchorpoint,
                                n.nodeaggregateidentifier,
                                h.dimensionspacepointhash
                             from
                                neos_contentgraph_node n
                             -- we need to join with the hierarchy relation, because we need the dimensionspacepointhash.
                             inner join neos_contentgraph_hierarchyrelation h
                                on h.childnodeanchor = n.relationanchorpoint
                             where
                                n.nodeaggregateidentifier = :entryNodeAggregateIdentifier
                                and h.contentstreamidentifier = :contentStreamIdentifier
                                and h.dimensionspacepointhash in (:dimensionSpacePointHashes)
                        union
                             -- --------------------------------
                             -- RECURSIVE query: do one "child" query step
                             -- --------------------------------
                             select
                                c.relationanchorpoint,
                                c.nodeaggregateidentifier,
                                h.dimensionspacepointhash
                             from
                                tree p
                             inner join neos_contentgraph_hierarchyrelation h
                                on h.parentnodeanchor = p.relationanchorpoint
                             inner join neos_contentgraph_node c
                                on h.childnodeanchor = c.relationanchorpoint
                             where
                                h.contentstreamidentifier = :contentStreamIdentifier
                                and h.dimensionspacepointhash in (:dimensionSpacePointHashes)
                        )
                        select * from tree
                     ) as tree

                -- the "tree" CTE now contains a list of tuples (nodeAggregateIdentifier,dimensionSpacePointHash)
                -- which are *descendants* of the starting NodeAggregateIdentifier (in the given DimensionSpacePointHashes).
                where
                    r.contentstreamidentifier = :contentStreamIdentifier
                    and r.dimensionspacepointhash = tree.dimensionspacepointhash
                    and r.affectednodeaggregateidentifier = tree.nodeaggregateidentifier
            ',
            [
                'entryNodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                'dimensionSpacePointHashes' => $affectedDimensionSpacePoints->getPointHashes()
            ],
            [
                'dimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
            ]);
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @throws \Doctrine\DBAL\DBALException
     */
    private function removeAllRestrictionEdgesUnderneathNodeAggregate(ContentStreamIdentifier $contentStreamIdentifier, NodeAggregateIdentifier $nodeAggregateIdentifier)
    {
        $this->getDatabaseConnection()->executeUpdate('
                -- GraphProjector::removeRestrictionEdgesUnderneathNodeAggregateAndDimensionSpacePoints
 
                delete r.* from
                    neos_contentgraph_restrictionedge r
                    join 
                     (
                        -- we build a recursive tree
                        with recursive tree as (
                             -- --------------------------------
                             -- INITIAL query: select the root nodes of the tree
                             -- --------------------------------
                             select
                                n.relationanchorpoint,
                                n.nodeaggregateidentifier,
                                h.dimensionspacepointhash
                             from
                                neos_contentgraph_node n
                             -- we need to join with the hierarchy relation, because we need the dimensionspacepointhash.
                             inner join neos_contentgraph_hierarchyrelation h
                                on h.childnodeanchor = n.relationanchorpoint
                             where
                                n.nodeaggregateidentifier = :entryNodeAggregateIdentifier
                                and h.contentstreamidentifier = :contentStreamIdentifier
                        union
                             -- --------------------------------
                             -- RECURSIVE query: do one "child" query step
                             -- --------------------------------
                             select
                                c.relationanchorpoint,
                                c.nodeaggregateidentifier,
                                h.dimensionspacepointhash
                             from
                                tree p
                             inner join neos_contentgraph_hierarchyrelation h
                                on h.parentnodeanchor = p.relationanchorpoint
                             inner join neos_contentgraph_node c
                                on h.childnodeanchor = c.relationanchorpoint
                             where
                                h.contentstreamidentifier = :contentStreamIdentifier
                        )
                        select * from tree
                     ) as tree

                -- the "tree" CTE now contains a list of tuples (nodeAggregateIdentifier,dimensionSpacePointHash)
                -- which are *descendants* of the starting NodeAggregateIdentifier in ALL DimensionSpacePointHashes
                where
                    r.contentstreamidentifier = :contentStreamIdentifier
                    and r.dimensionspacepointhash = tree.dimensionspacepointhash
                    and r.affectednodeaggregateidentifier = tree.nodeaggregateidentifier
            ',
            [
                'entryNodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            ]);
    }

    /**
     * @param NodeSpecializationVariantWasCreated $event
     * @throws \Exception
     * @throws \Throwable
     */
    public function whenNodeSpecializationVariantWasCreated(NodeSpecializationVariantWasCreated $event): void
    {
        $this->transactional(function () use ($event) {
            $sourceNode = $this->projectionContentGraph->getNodeInAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getSourceOrigin());

            $specializedNode = $this->copyNodeToDimensionSpacePoint($sourceNode, $event->getSpecializationOrigin());

            foreach ($this->projectionContentGraph->findInboundHierarchyRelationsForNode(
                $sourceNode->relationAnchorPoint,
                $event->getContentStreamIdentifier(),
                $event->getSpecializationCoverage()
            ) as $hierarchyRelation) {
                $hierarchyRelation->assignNewChildNode($specializedNode->relationAnchorPoint, $this->getDatabaseConnection());
            }
            foreach ($this->projectionContentGraph->findOutboundHierarchyRelationsForNode(
                $sourceNode->relationAnchorPoint,
                $event->getContentStreamIdentifier(),
                $event->getSpecializationCoverage()
            ) as $hierarchyRelation) {
                $hierarchyRelation->assignNewParentNode($specializedNode->relationAnchorPoint, null, $this->getDatabaseConnection());
            }
        });
    }

    /**
     * @param NodeGeneralizationVariantWasCreated $event
     * @throws \Exception
     * @throws \Throwable
     */
    public function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event): void
    {
        $this->transactional(function () use ($event) {
            $sourceNode = $this->projectionContentGraph->getNodeInAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getSourceOrigin());
            $sourceParentNode = $this->projectionContentGraph->findParentNode(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getSourceOrigin()
            );
            $generalizedNode = $this->copyNodeToDimensionSpacePoint($sourceNode, $event->getGeneralizationOrigin());

            $unassignedInboundDimensionSpacePoints = $event->getGeneralizationCoverage();
            foreach ($this->projectionContentGraph->findInboundHierarchyRelationsForNodeAggregate(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getGeneralizationCoverage()
            ) as $existingInboundHierarchyRelation) {
                $existingInboundHierarchyRelation->assignNewChildNode($generalizedNode->relationAnchorPoint, $this->getDatabaseConnection());
                $unassignedInboundDimensionSpacePoints = $unassignedInboundDimensionSpacePoints->getDifference(new DimensionSpacePointSet([$existingInboundHierarchyRelation->dimensionSpacePoint]));
            }

            foreach ($this->projectionContentGraph->findOutboundHierarchyRelationsForNodeAggregate(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getGeneralizationCoverage()
            ) as $existingOutboundHierarchyRelation) {
                $existingOutboundHierarchyRelation->assignNewParentNode($generalizedNode->relationAnchorPoint, null, $this->getDatabaseConnection());
            }

            if (count($unassignedInboundDimensionSpacePoints) > 0) {
                $inboundSourceHierarchyRelation = $this->projectionContentGraph->findInboundHierarchyRelationsForNode(
                        $sourceNode->relationAnchorPoint,
                        $event->getContentStreamIdentifier(),
                        new DimensionSpacePointSet([$event->getSourceOrigin()])
                    )[$event->getSourceOrigin()->getHash()] ?? null;
                // the null case is caught by the NodeAggregate or its command handler
                foreach ($unassignedInboundDimensionSpacePoints as $unassignedDimensionSpacePoint) {
                    // The parent node aggregate might be varied as well, so we need to find a parent node for each covered dimension space point
                    $generalizationParentNode = $this->projectionContentGraph->getNodeInAggregate(
                        $event->getContentStreamIdentifier(),
                        $sourceParentNode->nodeAggregateIdentifier,
                        $unassignedDimensionSpacePoint
                    );

                    $this->copyHierarchyRelationToDimensionSpacePoint(
                        $inboundSourceHierarchyRelation,
                        $event->getContentStreamIdentifier(),
                        $unassignedDimensionSpacePoint,
                        $generalizationParentNode->relationAnchorPoint,
                        $generalizedNode->relationAnchorPoint
                    );
                }
            }
        });
    }

    /**
     * @param Event\NodePeerVariantWasCreated $event
     * @throws \Throwable
     */
    public function whenNodePeerVariantWasCreated(Event\NodePeerVariantWasCreated $event)
    {
        $this->transactional(function () use ($event) {
            $sourceNode = $this->projectionContentGraph->getNodeInAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getSourceOrigin());
            $sourceParentNode = $this->projectionContentGraph->findParentNode(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getSourceOrigin()
            );
            $peerNode = $this->copyNodeToDimensionSpacePoint($sourceNode, $event->getPeerOrigin());

            $unassignedInboundDimensionSpacePoints = $event->getPeerCoverage();
            foreach ($this->projectionContentGraph->findInboundHierarchyRelationsForNodeAggregate(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getPeerCoverage()
            ) as $existingInboundHierarchyRelation) {
                $existingInboundHierarchyRelation->assignNewChildNode($peerNode->relationAnchorPoint, $this->getDatabaseConnection());
                $unassignedInboundDimensionSpacePoints = $unassignedInboundDimensionSpacePoints->getDifference(new DimensionSpacePointSet([$existingInboundHierarchyRelation->dimensionSpacePoint]));
            }

            foreach ($this->projectionContentGraph->findOutboundHierarchyRelationsForNodeAggregate(
                $event->getContentStreamIdentifier(),
                $event->getNodeAggregateIdentifier(),
                $event->getPeerCoverage()
            ) as $existingOutboundHierarchyRelation) {
                $existingOutboundHierarchyRelation->assignNewParentNode($peerNode->relationAnchorPoint, null, $this->getDatabaseConnection());
            }

            foreach ($unassignedInboundDimensionSpacePoints as $coveredDimensionSpacePoint) {
                // The parent node aggregate might be varied as well, so we need to find a parent node for each covered dimension space point
                $peerParentNode = $this->projectionContentGraph->getNodeInAggregate(
                    $event->getContentStreamIdentifier(),
                    $sourceParentNode->nodeAggregateIdentifier,
                    $coveredDimensionSpacePoint
                );

                $this->connectHierarchy(
                    $event->getContentStreamIdentifier(),
                    $peerParentNode->relationAnchorPoint,
                    $peerNode->relationAnchorPoint,
                    new DimensionSpacePointSet([$coveredDimensionSpacePoint]),
                    null, // @todo fetch appropriate sibling
                    $sourceNode->nodeName
                );
            }
        });
    }

    /**
     * @param NodesWereMoved $event
     * @throws \Throwable
     */
    public function whenNodesWereMoved(NodesWereMoved $event)
    {
        $this->transactional(function () use ($event) {
            foreach ($event->getNodeMoveMappings() as $moveNodeMapping) {
                $nodeToBeMoved = $this->projectionContentGraph->findNodeByIdentifiers(
                    $event->getContentStreamIdentifier(),
                    $event->getNodeAggregateIdentifier(),
                    $moveNodeMapping->getMovedNodeOrigin()
                );

                $newSucceedingSibling = null;
                if ($event->getNewSucceedingSiblingNodeAggregateIdentifier()) {
                    // @todo this might differ from DSP to DSP and has to be moved to the mapping as sibling aggregate identifier
                    $newSucceedingSibling = $this->projectionContentGraph->findNodeByIdentifiers(
                        $event->getContentStreamIdentifier(),
                        $event->getNewSucceedingSiblingNodeAggregateIdentifier(),
                        $moveNodeMapping->getNewSucceedingSiblingOrigin()
                    );
                }

                $inboundHierarchyRelations = $this->projectionContentGraph->findInboundHierarchyRelationsForNode($nodeToBeMoved->relationAnchorPoint, $event->getContentStreamIdentifier());
                if ($event->getNewParentNodeAggregateIdentifier()) {

                    // @todo remove inbound restriction edges of to-be-moved node
                    // @todo remove inbound restriction edges with same parent anchor as the above and child anchor of node's descendants

                    // 1. PRE-MOVE HOUSEKEEPING
                    // - of the to-be moved nodes, remove all restriction edges
                    // - TODO: this means that when moving a HIDDEN node itself (and none of its children), it will LOOSE its hidden state. TODO FIX!!!
                    //
                    /*
                    $this->removeOutgoingRestrictionEdgesOfNodeAggregateInDimensionSpacePoints(
                        $event->getContentStreamIdentifier(),
                        $event->getNodeAggregateIdentifier(),
                        $moveNodeMapping->getRelationDimensionSpacePoints()
                    );*/

                    //
                    // 2. do the MOVE ITSELF
                    //

                    $newParentNode = $this->projectionContentGraph->findNodeByIdentifiers(
                        $event->getContentStreamIdentifier(),
                        $event->getNewParentNodeAggregateIdentifier(),
                        $moveNodeMapping->getNewParentNodeOrigin()
                    );
                    foreach ($moveNodeMapping->getRelationDimensionSpacePoints() as $relationDimensionSpacePoint) {
                        $newPosition = $this->getRelationPosition(
                            $newParentNode->relationAnchorPoint,
                            null,
                            $newSucceedingSibling ? $newSucceedingSibling->relationAnchorPoint : null,
                            $event->getContentStreamIdentifier(),
                            $relationDimensionSpacePoint
                        );

                        $inboundHierarchyRelations[$relationDimensionSpacePoint->getHash()]->assignNewParentNode($newParentNode->relationAnchorPoint, $newPosition, $this->getDatabaseConnection());
                    }

                    // @todo connect inbound restriction edges of new parent to moved node and all of its descendants

                    //
                    // 3. POST-MOVE HOUSEKEEPING
                    // - if parent node is hidden, hide the moved-to target as well.
                    //
                    /*
                    $this->connectRestrictionEdgesFromParentNodeToNewlyCreatedNode(
                        $event->getContentStreamIdentifier(),
                        $newParentNode->nodeIdentifier,
                        $nodeToBeMoved->nodeAggregateIdentifier,
                        $moveNodeMapping->getDimensionSpacePointSet()
                    );*/
                } else {
                    foreach ($inboundHierarchyRelations as $inboundHierarchyRelation) {
                        $newPosition = $this->getRelationPosition(
                            null,
                            $nodeToBeMoved->relationAnchorPoint,
                            $newSucceedingSibling ? $newSucceedingSibling->relationAnchorPoint : null,
                            $event->getContentStreamIdentifier(),
                            $inboundHierarchyRelation->dimensionSpacePoint
                        );

                        $inboundHierarchyRelation->assignNewPosition($newPosition, $this->getDatabaseConnection());
                    }
                }
            }
        });
    }

    /**
     * @param NodesWereRemovedFromAggregate $event
     * @throws \Throwable
     */
    public function whenNodesWereRemovedFromAggregate(NodesWereRemovedFromAggregate $event)
    {
        // the focus here is to be correct; that's why the method is not overly performant (for now at least). We might
        // lateron find tricks to improve performance
        $this->transactional(function () use ($event) {
            $this->removeOutgoingRestrictionEdgesOfNodeAggregateInDimensionSpacePoints($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getDimensionSpacePointSet());
            $inboundRelations = $this->projectionContentGraph->findInboundHierarchyRelationsForNodeAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier(), $event->getDimensionSpacePointSet());
            foreach ($inboundRelations as $inboundRelation) {
                $this->removeRelationRecursivelyFromDatabaseIncludingNonReferencedNodes($inboundRelation);
            }
        });
    }

    /**
     * @param NodeAggregateWasRemoved $event
     * @throws \Throwable
     */
    public function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event)
    {
        // the focus here is to be correct; that's why the method is not overly performant (for now at least). We might
        // lateron find tricks to improve performance
        $this->transactional(function () use ($event) {
            $this->removeAllRestrictionEdgesUnderneathNodeAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier());

            $inboundRelations = $this->projectionContentGraph->findInboundHierarchyRelationsForNodeAggregate($event->getContentStreamIdentifier(), $event->getNodeAggregateIdentifier());
            foreach ($inboundRelations as $inboundRelation) {
                $this->removeRelationRecursivelyFromDatabaseIncludingNonReferencedNodes($inboundRelation);
            }
        });
    }

    /**
     * @param HierarchyRelation $inboundRelation
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function removeRelationRecursivelyFromDatabaseIncludingNonReferencedNodes(HierarchyRelation $inboundRelation)
    {
        $inboundRelation->removeFromDatabase($this->getDatabaseConnection());

        foreach ($this->projectionContentGraph->findOutboundHierarchyRelationsForNode($inboundRelation->childNodeAnchor, $inboundRelation->contentStreamIdentifier, new DimensionSpacePointSet([$inboundRelation->dimensionSpacePoint])) as $outboundRelation) {
            $this->removeRelationRecursivelyFromDatabaseIncludingNonReferencedNodes($outboundRelation);
        }

        // remove node itself if it does not have any incoming edges anymore
        $this->getDatabaseConnection()->executeUpdate('
            DELETE n FROM neos_contentgraph_node n
                LEFT JOIN
                    neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                WHERE
                    n.relationanchorpoint = :anchorPointForNode
                    AND h.contentstreamidentifier IS NULL
                ',
            [
                'anchorPointForNode' => (string)$inboundRelation->childNodeAnchor,
            ]
        );
    }

    protected function copyHierarchyRelationToDimensionSpacePoint(
        HierarchyRelation $sourceHierarchyRelation,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        ?NodeRelationAnchorPoint $newParent = null,
        ?NodeRelationAnchorPoint $newChild = null
    ): HierarchyRelation {
        $copy = new HierarchyRelation(
            $newParent ?: $sourceHierarchyRelation->parentNodeAnchor,
            $newChild ?: $sourceHierarchyRelation->childNodeAnchor,
            $sourceHierarchyRelation->name,
            $contentStreamIdentifier,
            $dimensionSpacePoint,
            $dimensionSpacePoint->getHash(),
            $this->getRelationPosition(
                $newParent ?: $sourceHierarchyRelation->parentNodeAnchor,
                $newChild ?: $sourceHierarchyRelation->childNodeAnchor,
                null, // todo: find proper sibling
                $contentStreamIdentifier,
                $dimensionSpacePoint
            )
        );
        $copy->addToDatabase($this->getDatabaseConnection());

        return $copy;
    }

    /**
     * @param NodeRecord $sourceNode
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return NodeRecord
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function copyNodeToDimensionSpacePoint(NodeRecord $sourceNode, DimensionSpacePoint $dimensionSpacePoint): NodeRecord
    {
        $copyRelationAnchorPoint = NodeRelationAnchorPoint::create();
        $copy = new NodeRecord(
            $copyRelationAnchorPoint,
            $sourceNode->nodeAggregateIdentifier,
            $dimensionSpacePoint->jsonSerialize(),
            $dimensionSpacePoint->getHash(),
            $sourceNode->properties,
            $sourceNode->nodeTypeName,
            $sourceNode->classification,
            $sourceNode->nodeName
        );
        $copy->addToDatabase($this->getDatabaseConnection());

        return $copy;
    }

    /**
     * @param callable $operations
     * @throws \Exception
     * @throws \Throwable
     */
    protected function transactional(callable $operations): void
    {
        $this->getDatabaseConnection()->transactional($operations);
        $this->emitProjectionUpdated();
    }

    /**
     * @param ContentRepository\Context\Node\CopyableAcrossContentStreamsInterface $event
     * @param callable $operations
     * @return mixed
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    protected function updateNodeWithCopyOnWrite(ContentRepository\Context\Node\CopyableAcrossContentStreamsInterface $event, callable $operations)
    {
        switch (get_class($event)) {
            case NodeReferencesWereSet::class:
                /** @var NodeReferencesWereSet $event */
                $anchorPointForNode = $this->projectionContentGraph->getAnchorPointForNodeAndOriginDimensionSpacePointAndContentStream(
                    $event->getSourceNodeAggregateIdentifier(),
                    $event->getSourceOriginDimensionSpacePoint(),
                    $event->getContentStreamIdentifier()
                );
                break;
            default:
                if (method_exists($event, 'getNodeAggregateIdentifier')) {
                    $anchorPointForNode = $this->projectionContentGraph->getAnchorPointForNodeAndOriginDimensionSpacePointAndContentStream(
                        $event->getNodeAggregateIdentifier(),
                        $event->getOriginDimensionSpacePoint(),
                        $event->getContentStreamIdentifier()
                    );
                }
        }

        $contentStreamIdentifiers = $this->projectionContentGraph->getAllContentStreamIdentifiersAnchorPointIsContainedIn($anchorPointForNode);
        if (count($contentStreamIdentifiers) > 1) {
            // Copy on Write needed!
            // Copy on Write is a purely "Content Stream" related concept; thus we do not care about different DimensionSpacePoints here (but we copy all edges)

            // 1) fetch node, adjust properties, assign new Relation Anchor Point
            $copiedNode = $this->projectionContentGraph->getNodeByAnchorPoint($anchorPointForNode);
            $copiedNode->relationAnchorPoint = NodeRelationAnchorPoint::create();
            $result = $operations($copiedNode);
            $copiedNode->addToDatabase($this->getDatabaseConnection());

            // 2) reconnect all edges belonging to this content stream to the new "copied node". IMPORTANT: We need to reconnect
            // BOTH the incoming and outgoing edges.
            $this->getDatabaseConnection()->executeUpdate('
                UPDATE neos_contentgraph_hierarchyrelation h
                    SET 
                        -- if our (copied) node is the child, we update h.childNodeAnchor
                        h.childnodeanchor = IF(h.childnodeanchor = :originalNodeAnchor, :newNodeAnchor, h.childnodeanchor),
                        
                        -- if our (copied) node is the parent, we update h.parentNodeAnchor
                        h.parentnodeanchor = IF(h.parentnodeanchor = :originalNodeAnchor, :newNodeAnchor, h.parentnodeanchor)
                    WHERE
                      :originalNodeAnchor IN (h.childnodeanchor, h.parentnodeanchor)
                      AND h.contentstreamidentifier = :contentStreamIdentifier',
                [
                    'newNodeAnchor' => (string)$copiedNode->relationAnchorPoint,
                    'originalNodeAnchor' => (string)$anchorPointForNode,
                    'contentStreamIdentifier' => (string)$event->getContentStreamIdentifier()
                ]
            );
        } else {
            // No copy on write needed :)

            $node = $this->projectionContentGraph->getNodeByAnchorPoint($anchorPointForNode);
            if (!$node) {
                // TODO: ignore the ShowNode (if all other logic is correct)
                throw new \Exception("TODO NODE NOT FOUND");
            }

            $result = $operations($node);
            $node->updateToDatabase($this->getDatabaseConnection());
        }
        return $result;
    }

    /**
     * @return Connection
     */
    protected function getDatabaseConnection(): Connection
    {
        return $this->client->getConnection();
    }

    /**
     * @param EventEnvelope $eventEnvelope
     * @throws \Neos\Cache\Exception
     */
    public function afterInvoke(EventEnvelope $eventEnvelope): void
    {
        $this->processedEventsCache->set(md5($eventEnvelope->getRawEvent()->getIdentifier()), true);
    }
}
