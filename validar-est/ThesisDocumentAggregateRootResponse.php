<?php

namespace AcademicObligations\ThesisDocuments\Responses;

use AcademicObligations\ThesisDocuments\Models\Attachment;
use AcademicObligations\ThesisDocuments\Models\ThesisDocument;
use AcademicObligations\ThesisDocuments\Models\Rules;

/**
 * Class ThesisDocumentAggregateRootResponse
 * @package AcademicObligations\ThesisDocuments\Responses
 */
class ThesisDocumentAggregateRootResponse extends ModelResponseSchema
{
  /**
   * @inheritdoc
   * @return array
   */
  public function map(): array
  {
    /** @var ThesisDocument $model */
    $model = $this->model;
    $metadata = $model->metadata;
    $rules = new Rules();
    return [
      'uuid' => $model->uuid,
      'thesis' => $model->thesis->only(['uuid', 'title', 'status']),
      'student' => $model->student->only(['uuid', 'name', 'last_name']),
      'status' => $model->status,
      'attachments' => $model->attachments?->map(
        fn(Attachment $attachment) =>
        $attachment->only(['uuid', 'name'])
      ),
      'metadata' => [
        'uploaded_at' => $metadata?->uploaded_at?->toISOString(),
        'approved_at' => $metadata?->approved_at?->toISOString(),
        'rejected_at' => $metadata?->rejected_at?->toISOString(),
        'thesis_status' => $metadata?->thesis_status,
        'thesis_sub_status' => $metadata?->thesis_sub_status,
      ],
      'created_at' => $model->created_at?->toISOString(),
      'updated_at' => $model->updated_at?->toISOString(),
      'deleted_at' => $model->deleted_at?->toISOString(),
      'rules' => $rules
    ];
  }
}
