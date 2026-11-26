<?php

namespace App\Services\Media\Classification;

/**
 * One way of telling what a picture shows — a cloud moderation API or a model
 * run on the host. Every classifier answers in ContentCategory's terms, so
 * results compare and decide alike whatever their provider calls things.
 */
interface MediaContentClassifier
{
    /** A short stable name, as results record it (e.g. "aws-rekognition", "nudenet"); kept to 64 characters. */
    public function name(): string;

    /** The model or API version answering, as results record it; kept to 64 characters. */
    public function modelVersion(): ?string;

    /**
     * @throws MediaClassificationFailedException when it gives no answer
     */
    public function classify(ClassificationInput $input): ClassificationResult;
}
