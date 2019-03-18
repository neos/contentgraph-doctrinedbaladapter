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

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Projection\Content\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\ValueObject\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\ValueObject\NodeAggregateIdentifier;
use Neos\ContentRepository\Exception\NodeConfigurationException;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\NodeAggregateIsAmbiguous;
use Neos\EventSourcedContentRepository\Domain\Projection\Content as ContentProjection;
use Neos\ContentRepository\Domain\ValueObject\NodeName;
use Neos\ContentRepository\Domain\ValueObject\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;

/**
 * Implementation detail of ContentGraph and ContentSubgraph
 *
 * @Flow\Scope("singleton")
 */
final class NodeFactory
{
    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;


    /**
     * @param array $nodeRow Node Row from projection (neos_contentgraph_node table)
     * @return NodeInterface
     * @throws \Exception
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeConfigurationException
     * @throws \Neos\EventSourcedContentRepository\Exception\NodeTypeNotFoundException
     */
    public function mapNodeRowToNode(array $nodeRow): NodeInterface
    {
        $nodeType = $this->nodeTypeManager->getNodeType($nodeRow['nodetypename']);
        $className = $this->getNodeInterfaceImplementationClassName($nodeType);

        if (!array_key_exists('dimensionspacepoint', $nodeRow)) {
            throw new \Exception('The "dimensionspacepoint" property was not found in the $nodeRow; you need to include the "dimensionspacepoint" field in the SQL result.');
        }
        if (!array_key_exists('contentstreamidentifier', $nodeRow)) {
            throw new \Exception('The "contentstreamidentifier" property was not found in the $nodeRow; you need to include the "contentstreamidentifier" field in the SQL result.');
        }
        if (!array_key_exists('origindimensionspacepoint', $nodeRow)) {
            throw new \Exception('The "origindimensionspacepoint" property was not found in the $nodeRow; you need to include the "origindimensionspacepoint" field in the SQL result.');
        }

        $contentStreamIdentifier = ContentStreamIdentifier::fromString($nodeRow['contentstreamidentifier']);
        $dimensionSpacePoint = DimensionSpacePoint::fromJsonString($nodeRow['dimensionspacepoint']);
        $originDimensionSpacePoint = DimensionSpacePoint::fromJsonString($nodeRow['origindimensionspacepoint']);

        $properties = json_decode($nodeRow['properties'], true);

        // Reference and References "are no properties" anymore by definition; so Node does not know
        // anything about it.
        $properties = array_filter($properties, function ($propertyName) use ($nodeType) {
            $propertyType = $nodeType->getPropertyType($propertyName);
            return $propertyType !== 'reference' && $propertyType !== 'references';
        }, ARRAY_FILTER_USE_KEY);

        $propertyCollection = new ContentProjection\PropertyCollection($properties);

        /* @var $node NodeInterface */
        $node = new $className(
            $contentStreamIdentifier,
            $dimensionSpacePoint,
            NodeAggregateIdentifier::fromString($nodeRow['nodeaggregateidentifier']),
            $originDimensionSpacePoint,
            NodeTypeName::fromString($nodeRow['nodetypename']),
            $nodeType,
            isset($nodeRow['name']) ? NodeName::fromString($nodeRow['name']) : NodeName::unnamed(),
            $propertyCollection
        );

        if (!array_key_exists('name', $nodeRow)) {
            throw new \Exception('The "name" property was not found in the $nodeRow; you need to include the "name" field in the SQL result.');
        }
        return $node;
    }

    /**
     * @param array $nodeRows
     * @return ContentProjection\NodeAggregate
     * @throws \Exception
     */
    public function mapNodeRowsToNodeAggregate(array $nodeRows): ContentProjection\NodeAggregate
    {
        $rawNodeAggregateIdentifier = '';
        $rawNodeTypeName = '';
        $rawNodeName = '';
        $nodes = [];
        $occupiedDimensionSpacePoints = [];
        $coveredDimensionSpacePoints = [];

        $processedDimensionSpacePoints = [];
        foreach ($nodeRows as $nodeRow) {
            if (!isset($processedDimensionSpacePoints[$nodeRow['origindimensionspacepointhash']])) {
                $nodes[] = $this->mapNodeRowToNode($nodeRow);
                $occupiedDimensionSpacePoints[] = DimensionSpacePoint::fromJsonString($nodeRow['origindimensionspacepoint']);
                if (empty($rawNodeAggregateIdentifier)) {
                    $rawNodeAggregateIdentifier = $nodeRow['nodeaggregateidentifier'];
                } elseif ($rawNodeAggregateIdentifier !== $nodeRow['nodeaggregateidentifier']) {
                    throw new NodeAggregateIsAmbiguous('Node aggregate is ambiguous', 1552691226);
                }
                if (empty($rawNodeTypeName)) {
                    $rawNodeTypeName = $nodeRow['nodetypename'];
                }
                if (empty($rawNodeName)) {
                    $rawNodeName = $nodeRow['name'];
                }
            }
            $coveredDimensionSpacePoints[] = DimensionSpacePoint::fromJsonString($nodeRow['dimensionspacepoint']);
        }

        return new ContentProjection\NodeAggregate(
            NodeAggregateIdentifier::fromString($rawNodeAggregateIdentifier),
            NodeTypeName::fromString($rawNodeTypeName),
            $rawNodeName ? NodeName::fromString($rawNodeName) : null,
            $nodes,
            new DimensionSpacePointSet($occupiedDimensionSpacePoints),
            new DimensionSpacePointSet($coveredDimensionSpacePoints)
        );
    }

    protected function getNodeInterfaceImplementationClassName(NodeType $nodeType): string
    {
        $customClassName = $nodeType->getConfiguration('class');
        if (!empty($customClassName)) {
            if (!class_exists($customClassName)) {
                throw new NodeConfigurationException('The configured implementation class name "' . $customClassName . '" for NodeType "' . $nodeType . '" does not exist.', 1505805774);
            } elseif (!$this->reflectionService->isClassImplementationOf($customClassName, NodeInterface::class)) {
                if ($this->reflectionService->isClassImplementationOf($customClassName, \Neos\ContentRepository\Domain\Model\NodeInterface::class)) {
                    throw new NodeConfigurationException('The configured implementation class name "' . $customClassName . '" for NodeType "' . $nodeType. '" inherits from the OLD (pre-event-sourced) NodeInterface; which is not supported anymore. Your custom Node class now needs to implement ' . NodeInterface::class . '.', 1520069750);
                }
                throw new NodeConfigurationException('The configured implementation class name "' . $customClassName . '" for NodeType "' . $nodeType. '" does not inherit from ' . NodeInterface::class . '.', 1406884014);
            }
            return $customClassName;
        } else {
            return $this->objectManager->getClassNameByObjectName(NodeInterface::class);
        }
    }
}
