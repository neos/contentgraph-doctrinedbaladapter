<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\Feature;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Common\EmbedsContentStreamId;
use Neos\ContentRepository\Core\Feature\Common\PublishableToWorkspaceInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\Model\Event\Version;

/**
 * The ContentStream projection feature trait
 *
 * @internal
 */
trait ContentStream
{
    private function createContentStream(ContentStreamId $contentStreamId, ?ContentStreamId $sourceContentStreamId = null, ?Version $sourceVersion = null): void
    {
        $this->dbal->insert($this->tableNames->contentStream(), [
            'id' => $contentStreamId->value,
            'version' => 0,
            'sourceContentStreamId' => $sourceContentStreamId?->value,
            'sourceContentStreamVersion' => $sourceVersion?->value,
            'closed' => 0,
            'dirty' => 0
        ]);
    }

    private function closeContentStream(ContentStreamId $contentStreamId): void
    {
        $this->dbal->update($this->tableNames->contentStream(), [
            'closed' => 1,
        ], [
            'id' => $contentStreamId->value
        ]);
    }

    private function reopenContentStream(ContentStreamId $contentStreamId): void
    {
        $this->dbal->update($this->tableNames->contentStream(), [
            'closed' => 0,
        ], [
            'id' => $contentStreamId->value
        ]);
    }

    private function removeContentStream(ContentStreamId $contentStreamId): void
    {
        $this->dbal->delete($this->tableNames->contentStream(), [
            'id' => $contentStreamId->value
        ]);
    }

    private function updateContentStreamVersion(EventInterface&EmbedsContentStreamId $event, Version $version): void
    {
        // todo make fork content stream `EmbedsContentStreamId` but then just ignore it here because we set the version already
        $isPublishableEvent = $event instanceof PublishableToWorkspaceInterface;
        $updatePayload = [
            'version' => $version->value,
        ];
        if ($isPublishableEvent) {
            $updatePayload['dirty'] = 1;
        }
        $this->dbal->update($this->tableNames->contentStream(), $updatePayload, [
            'id' => $event->getContentStreamId()->value,
        ]);
    }
}
