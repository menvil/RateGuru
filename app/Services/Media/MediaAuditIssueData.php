<?php

namespace App\Services\Media;

use App\Enums\MediaAuditIssueType;

/**
 * One finding MediaAuditService::run() reports via its $onIssue callback.
 * Severity is deliberately not a constructor param — it's a fixed function
 * of $issueType (MediaAuditIssueType::severity()), so it can never drift out
 * of sync between an issue and its type.
 */
final readonly class MediaAuditIssueData
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public MediaAuditIssueType $issueType,
        public ?int $mediaAssetId = null,
        public ?int $mediaVariantId = null,
        public ?string $disk = null,
        public ?string $path = null,
        public ?array $context = null,
    ) {}
}
