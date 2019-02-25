<?php
declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository;

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
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\ValueObject\NodeName;
use Neos\EventSourcedContentRepository\Domain\Context\Node\NodeIdentifier;
use Neos\EventSourcedContentRepository\Service\Infrastructure\Service\DbalClient;
use Neos\EventSourcedContentRepository\Domain;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\ContentGraphInterface;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\ContentSubgraphInterface;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\NodeAggregate;
use Neos\ContentRepository\Domain\ValueObject\ContentStreamIdentifier;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ValueObject\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\ValueObject\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Projection\Content\NodeInterface;


/**
 * The Doctrine DBAL adapter content graph
 *
 * To be used as a read-only source of nodes
 *
 * @Flow\Scope("singleton")
 * @api
 */
final class ContentGraph implements ContentGraphInterface
{
    /**
     * @Flow\Inject
     * @var DbalClient
     */
    protected $client;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @var array|ContentSubgraphInterface[]
     */
    protected $subgraphs;

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return ContentSubgraphInterface|null
     */
    final public function getSubgraphByIdentifier(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        Domain\Context\Parameters\VisibilityConstraints $visibilityConstraints
    ): ?ContentSubgraphInterface {
        $index = (string)$contentStreamIdentifier . '-' . $dimensionSpacePoint->getHash() . '-' . $visibilityConstraints->getHash();
        if (!isset($this->subgraphs[$index])) {
            $this->subgraphs[$index] = new ContentSubgraph($contentStreamIdentifier, $dimensionSpacePoint, $visibilityConstraints);
        }

        return $this->subgraphs[$index];
    }

    /**
     * Find a node by node identifier and content stream identifier
     *
     * Note: This does not pass the CR context to the node!!!
     *
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeIdentifier $nodeIdentifier
     * @return NodeInterface|null
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeConfigurationException
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeTypeNotFoundException
     */
    public function findNodeByIdentifierInContentStream(ContentStreamIdentifier $contentStreamIdentifier, NodeIdentifier $nodeIdentifier): ?NodeInterface
    {
        $connection = $this->client->getConnection();

        // TODO: we get an arbitrary DimensionSpacePoint returned here -- and this is actually a problem I guess...
        // TODO think through in detail
        // HINT: we check the ContentStreamIdentifier on the EDGE; as this is where we actually find out whether the node exists in the content stream
        $nodeRow = $connection->executeQuery(
            'SELECT n.*, h.contentstreamidentifier, h.name, h.dimensionspacepoint FROM neos_contentgraph_node n
                  INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                  WHERE n.nodeidentifier = :nodeIdentifier
                  AND h.contentstreamidentifier = :contentStreamIdentifier',
            [
                'nodeIdentifier' => (string)$nodeIdentifier,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier
            ]
        )->fetch();

        return $nodeRow ? $this->nodeFactory->mapNodeRowToNode($nodeRow) : null;
    }

    /**
     * @param NodeIdentifier $nodeIdentifier
     * @return NodeInterface|null
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findNodeByIdentifier(NodeIdentifier $nodeIdentifier): ?NodeInterface
    {
        $connection = $this->client->getConnection();

        // @todo remove fetching additional dimension space point once the matter is resolved
        // HINT: we check the ContentStreamIdentifier on the EDGE; as this is where we actually find out whether the node exists in the content stream
        $nodeRow = $connection->executeQuery(
            'SELECT n.*, h.contentstreamidentifier, h.name, n.origindimensionspacepoint, n.origindimensionspacepoint AS dimensionspacepoint FROM neos_contentgraph_node n
                  INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                  WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
                  AND n.origindimensionspacepointhash = :originDimensionSpacePointHash
                  AND h.contentstreamidentifier = :contentStreamIdentifier',
            [
                'nodeAggregateIdentifier' => (string)$nodeIdentifier->getNodeAggregateIdentifier(),
                'originDimensionSpacePointHash' => $nodeIdentifier->getOriginDimensionSpacePoint()->getHash(),
                'contentStreamIdentifier' => (string)$nodeIdentifier->getContentStreamIdentifier()
            ]
        )->fetch();

        return $nodeRow ? $this->nodeFactory->mapNodeRowToNode($nodeRow) : null;
    }

    /**
     * Find all nodes of a node aggregate by node aggregate identifier and content stream identifier
     *
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePointSet
     * @return array<NodeInterface>|NodeInterface[]
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findNodesByNodeAggregateIdentifier(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        DimensionSpacePointSet $dimensionSpacePointSet = null
    ): array {
        $connection = $this->client->getConnection();

        $query = 'SELECT n.*, h.name, h.contentstreamidentifier, h.dimensionspacepoint FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
 WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
 AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier
        ];
        $types = [];
        if ($dimensionSpacePointSet) {
            $query .= ' AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)';
            $parameters['dimensionSpacePointHashes'] = $dimensionSpacePointSet->getPointHashes();
            $types['dimensionSpacePointHashes'] = Connection::PARAM_STR_ARRAY;
        }

        $result = [];
        foreach ($connection->executeQuery(
            $query,
            $parameters,
            $types
        )->fetchAll() as $nodeRow) {
            $result[] = $this->nodeFactory->mapNodeRowToNode($nodeRow, null);
        }

        return $result;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param Domain\Service\Context|null $context
     * @return NodeAggregate|null
     * @throws Domain\Context\Node\NodeAggregatesTypeIsAmbiguous
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeConfigurationException
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeTypeNotFoundException
     */
    public function findNodeAggregateByIdentifier(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        Domain\Service\Context $context = null
    ): ?NodeAggregate {
        $connection = $this->client->getConnection();

        $query = 'SELECT n.*, h.name, h.contentstreamidentifier, h.dimensionspacepoint FROM neos_contentgraph_node n
                      INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                      WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
                      AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier
        ];

        $nodeRows = $connection->executeQuery($query, $parameters)->fetchAll();
        if (empty($nodeRows)) {
            return null;
        }

        $rawNodeTypeName = null;
        $rawNodeName = null;
        $nodes = [];
        foreach ($nodeRows as $nodeRow) {
            if (!$rawNodeTypeName) {
                $rawNodeTypeName = $nodeRow['nodetypename'];
            } elseif ($nodeRow['nodetypename'] !== $rawNodeTypeName) {
                throw new Domain\Context\Node\NodeAggregatesTypeIsAmbiguous('Node aggregate "' . $nodeAggregateIdentifier . '" has an ambiguous type.', 1519815810);
            }
            if (!$rawNodeName) {
                $rawNodeName = $nodeRow['name'];
            } elseif ($nodeRow['name'] !== $rawNodeName) {
                throw new Domain\Context\Node\NodeAggregatesNameIsAmbiguous('Node aggregate "' . $nodeAggregateIdentifier . '" has an ambiguous name.', 1519919025);
            }
            $nodes[] = $this->nodeFactory->mapNodeRowToNode($nodeRow, $context);
        }

        return new NodeAggregate($nodeAggregateIdentifier, NodeTypeName::fromString($rawNodeTypeName), NodeName::fromString($rawNodeName), $nodes);
    }

    /**
     * @param NodeTypeName $nodeTypeName
     * @return NodeInterface|null
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    public function findRootNodeByType(NodeTypeName $nodeTypeName): ?NodeInterface
    {
        $connection = $this->client->getConnection();

        // TODO: this code might still be broken somehow; because we are not in a DimensionSpacePoint / ContentStreamIdentifier here!
        $nodeRow = $connection->executeQuery(
            'SELECT n.*, h.contentstreamidentifier, h.name, h.dimensionspacepoint FROM neos_contentgraph_node n
                    INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                  WHERE n.nodetypename = :nodeTypeName',
            [
                'nodeTypeName' => (string)$nodeTypeName,
            ]
        )->fetch();

        return $nodeRow ? $this->nodeFactory->mapNodeRowToNode($nodeRow, null) : null;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return array|NodeAggregate[]
     * @throws Domain\Context\Node\NodeAggregatesNameIsAmbiguous
     * @throws Domain\Context\Node\NodeAggregatesTypeIsAmbiguous
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    public function findParentAggregates(ContentStreamIdentifier $contentStreamIdentifier, NodeAggregateIdentifier $nodeAggregateIdentifier): array
    {
        $connection = $this->client->getConnection();

        $query = 'SELECT p.*, ph.name, ph.contentstreamidentifier, ph.dimensionspacepoint FROM neos_contentgraph_node p
                      INNER JOIN neos_contentgraph_hierarchyrelation ph ON ph.childnodeanchor = p.relationanchorpoint
                      INNER JOIN neos_contentgraph_hierarchyrelation ch ON ch.parentnodeanchor = p.relationanchorpoint
                      INNER JOIN neos_contentgraph_node c ON ch.childnodeanchor = c.relationanchorpoint 
                      WHERE ph.contentstreamidentifier = :contentStreamIdentifier
                      AND ch.contentstreamidentifier = :contentStreamIdentifier';
        $query = 'SELECT p.* FROM neos_contentgraph_node p';
        $parameters = [
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier
        ];

        $parentAggregates = [];
        $rawNodeTypeNames = [];
        $rawNodeNames = [];
        $nodesByAggregate = [];
        \Neos\Flow\var_dump($parameters);
        \Neos\Flow\var_dump($connection->executeQuery($query, $parameters)->fetchAll(), 'parents');
        exit();
        foreach ($connection->executeQuery($query, $parameters)->fetchAll() as $nodeRow) {
            \Neos\Flow\var_dump($nodeRow, 'row');
            $rawNodeAggregateIdentifier = $nodeRow['nodeaggregateidentifier'];
            $nodesByAggregate[$rawNodeAggregateIdentifier][$nodeRow['dimensionspacepointhash']] = $this->nodeFactory->mapNodeRowToNode($nodeRow);
            if (!isset($rawNodeTypeNames[$rawNodeAggregateIdentifier])) {
                $rawNodeTypeNames[$rawNodeAggregateIdentifier] = $nodeRow['nodetypename'];
            } elseif ($nodeRow['nodetypename'] !== $rawNodeTypeNames[$rawNodeAggregateIdentifier]) {
                throw new Domain\Context\Node\NodeAggregatesTypeIsAmbiguous('Node aggregate "' . $rawNodeAggregateIdentifier . '" has an ambiguous node type.', 1519815810);
            }
            if (!isset($rawNodeNames[$rawNodeAggregateIdentifier])) {
                $rawNodeNames[$rawNodeAggregateIdentifier] = $nodeRow['name'];
            } elseif ($nodeRow['name'] !== $rawNodeNames[$rawNodeAggregateIdentifier]) {
                throw new Domain\Context\Node\NodeAggregatesNameIsAmbiguous('Node aggregate "' . $rawNodeAggregateIdentifier . '" has an ambiguous name.', 1519918382);
            }
        }
        foreach ($nodesByAggregate as $rawNodeAggregateIdentifier => $nodes) {
            $parentAggregates[$rawNodeAggregateIdentifier] = new NodeAggregate(
                NodeAggregateIdentifier::fromString($rawNodeAggregateIdentifier),
                NodeTypeName::fromString($rawNodeTypeNames[$rawNodeAggregateIdentifier]),
                isset($rawNodeNames[$rawNodeAggregateIdentifier]) ? NodeName::fromString($rawNodeNames[$rawNodeAggregateIdentifier]) : null,
                $nodesByAggregate[$rawNodeAggregateIdentifier]
            );
        }

        return $parentAggregates;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return array
     * @throws Domain\Context\Node\NodeAggregatesNameIsAmbiguous
     * @throws Domain\Context\Node\NodeAggregatesTypeIsAmbiguous
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    public function findChildAggregates(ContentStreamIdentifier $contentStreamIdentifier, NodeAggregateIdentifier $nodeAggregateIdentifier): array
    {
        $nodeIdentifiers = [];
        foreach ($this->findNodesByNodeAggregateIdentifier($contentStreamIdentifier, $nodeAggregateIdentifier) as $node) {
            $nodeIdentifiers[] = (string)$node->getNodeIdentifier();
        }

        $connection = $this->client->getConnection();

        $query = 'SELECT c.*, ch.name, ch.contentstreamidentifier, ch.dimensionspacepoint FROM neos_contentgraph_node p
                      INNER JOIN neos_contentgraph_hierarchyrelation ph ON ph.childnodeanchor = p.relationanchorpoint
                      INNER JOIN neos_contentgraph_hierarchyrelation ch ON ch.parentnodeanchor = p.relationanchorpoint
                      INNER JOIN neos_contentgraph_node c ON ch.childnodeanchor = c.relationanchorpoint 
                      WHERE p.nodeaggregateidentifier = :nodeAggregateIdentifier
                      AND ph.contentstreamidentifier = :contentStreamIdentifier
                      AND ch.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeAggregateIdentifier' => (string) $nodeAggregateIdentifier,
            'contentStreamIdentifier' => (string) $contentStreamIdentifier
        ];

        $childAggregates = [];
        $rawNodeTypeNames = [];
        $rawNodeNames = [];
        $nodesByAggregate = [];
        foreach ($connection->executeQuery($query, $parameters)->fetchAll() as $nodeRow) {
            $rawNodeAggregateIdentifier = $nodeRow['nodeaggregateidentifier'];
            $nodesByAggregate[$rawNodeAggregateIdentifier][$nodeRow['dimensionspacepointhash']] = $this->nodeFactory->mapNodeRowToNode($nodeRow);
            if (!isset($rawNodeTypeNames[$rawNodeAggregateIdentifier])) {
                $rawNodeTypeNames[$rawNodeAggregateIdentifier] = $nodeRow['nodetypename'];
            } elseif ($nodeRow['nodetypename'] !== $rawNodeTypeNames[$rawNodeAggregateIdentifier]) {
                throw new Domain\Context\Node\NodeAggregatesTypeIsAmbiguous('Node aggregate "' . $rawNodeAggregateIdentifier . '" has an ambiguous node type.', 1519815810);
            }
            if (!isset($rawNodeNames[$rawNodeAggregateIdentifier])) {
                $rawNodeNames[$rawNodeAggregateIdentifier] = $nodeRow['name'];
            } elseif ($nodeRow['name'] !== $rawNodeNames[$rawNodeAggregateIdentifier]) {
                throw new Domain\Context\Node\NodeAggregatesNameIsAmbiguous('Node aggregate "' . $rawNodeAggregateIdentifier . '" has an ambiguous name.', 1519918382);
            }
        }
        foreach ($nodesByAggregate as $rawNodeAggregateIdentifier => $nodes) {
            $childAggregates[$rawNodeAggregateIdentifier] = new NodeAggregate(
                NodeAggregateIdentifier::fromString($rawNodeAggregateIdentifier),
                NodeTypeName::fromString($rawNodeTypeNames[$rawNodeAggregateIdentifier]),
                isset($rawNodeNames[$rawNodeAggregateIdentifier]) ? NodeName::fromString($rawNodeNames[$rawNodeAggregateIdentifier]) : null,
                $nodesByAggregate[$rawNodeAggregateIdentifier]
            );
        }

        return $childAggregates;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeName $nodeName
     * @param NodeAggregateIdentifier $parentNodeAggregateIdentifier
     * @param DimensionSpacePoint $parentNodeDimensionSpacePoint
     * @param DimensionSpacePointSet $dimensionSpacePointsToCheck
     * @return DimensionSpacePointSet
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getDimensionSpacePointsOccupiedByChildNodeName(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeName $nodeName,
        NodeAggregateIdentifier $parentNodeAggregateIdentifier,
        DimensionSpacePoint $parentNodeDimensionSpacePoint,
        DimensionSpacePointSet $dimensionSpacePointsToCheck
    ) {
        $connection = $this->client->getConnection();

        $query = 'SELECT h.dimensionspacepoint, h.dimensionspacepointhash FROM neos_contentgraph_hierarchyrelation h
                      INNER JOIN neos_contentgraph_node n ON h.parentnodeanchor = n.relationanchorpoint
                      INNER JOIN neos_contentgraph_hierarchyrelation ph ON ph.childnodeanchor = n.relationanchorpoint
                      WHERE n.nodeaggregateidentifier = :parentNodeAggregateIdentifier
                      AND n.dimensionspacepointhash = :parentNodeDimensionSpacePoint
                      AND ph.contentstreamidentifier = :contentStreamIdentifier
                      AND h.contentstreamidentifier = :contentStreamIdentifier
                      AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)
                      AND h.name = :nodeName';
        $parameters = [
            'parentNodeAggregateIdentifier' => (string)$parentNodeAggregateIdentifier,
            'parentNodeDimensionSpacePoint' => $parentNodeDimensionSpacePoint->getHash(),
            'contentStreamIdentifier' => (string) $contentStreamIdentifier,
            'dimensionSpacePointHashes' => $dimensionSpacePointsToCheck->getPointHashes(),
            'nodeName' => (string) $nodeName
        ];
        $dimensionSpacePoints = [];
        foreach ($connection->executeQuery($query, $parameters)->fetchAll() as $hierarchyRelationData) {
            $dimensionSpacePoints[$hierarchyRelationData['dimensionspacepointhash']] = new DimensionSpacePoint(json_decode($hierarchyRelationData['dimensionspacepoint'], true));
        }

        return new DimensionSpacePointSet($dimensionSpacePoints);
    }

    /**
     * @param NodeInterface $node
     * @return DimensionSpacePointSet
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findVisibleDimensionSpacePointsOfNode(NodeInterface $node): DimensionSpacePointSet
    {
        $connection = $this->client->getConnection();

        $query = 'SELECT h.dimensionspacepoint, h.dimensionspacepointhash FROM neos_contentgraph_node n
                      INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                      WHERE n.nodeidentifier = :nodeIdentifier
                      AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeIdentifier' => (string)$node->getNodeIdentifier(),
            'contentStreamIdentifier' => (string)$node->getContentStreamIdentifier()
        ];
        $dimensionSpacePoints = [];
        foreach ($connection->executeQuery($query, $parameters)->fetchAll() as $hierarchyRelationData) {
            $dimensionSpacePoints[$hierarchyRelationData['dimensionspacepointhash']] = new DimensionSpacePoint(json_decode($hierarchyRelationData['dimensionspacepoint'], true));
        }

        return new DimensionSpacePointSet($dimensionSpacePoints);
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return DimensionSpacePointSet
     * @throws \Doctrine\DBAL\DBALException
     */
    public function findVisibleDimensionSpacePointsOfNodeAggregate(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): DimensionSpacePointSet {
        $connection = $this->client->getConnection();

        $query = 'SELECT h.dimensionspacepoint, h.dimensionspacepointhash FROM neos_contentgraph_node n
                      INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
                      WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
                      AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeAggregateIdentifier' => $nodeAggregateIdentifier,
            'contentStreamIdentifier' => $contentStreamIdentifier
        ];
        $dimensionSpacePoints = [];
        foreach ($connection->executeQuery($query, $parameters)->fetchAll() as $hierarchyRelationData) {
            $dimensionSpacePoints[$hierarchyRelationData['dimensionspacepointhash']] = new DimensionSpacePoint(json_decode($hierarchyRelationData['dimensionspacepoint'], true));
        }

        return new DimensionSpacePointSet($dimensionSpacePoints);
    }

    public function resetCache()
    {
        if (is_array($this->subgraphs)) {
            foreach ($this->subgraphs as $subgraph) {
                $subgraph->getInMemoryCache()->reset();
            }
        }
    }
}
