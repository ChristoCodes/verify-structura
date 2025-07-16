<?php

namespace AcademicObligations\ThesisDocuments\Actions\ThesisDocuments;

use AcademicObligations\ThesisDocuments\Actions\Action;
use AcademicObligations\ThesisDocuments\DomainEvents\ThesisDocumentApprovedEvent;
use AcademicObligations\ThesisDocuments\DomainEvents\ThesisDocumentRejectedEvent;
use AcademicObligations\ThesisDocuments\Models\Enums\ThesisDocumentStatusEnum;
use AcademicObligations\ThesisDocuments\Models\ThesisDocument;
use AcademicObligations\ThesisDocuments\Requests\ThesisDocuments\ThesisDocumentsStatusChangeRequest;
use AcademicObligations\ThesisDocuments\Responses\ThesisDocumentAggregateRootResponse;
use Illuminate\Support\Facades\DB;
use SlothDevGuy\RabbitMQMessages\RabbitMQMessage;
use AcademicObligations\ThesisDocuments\Models\Rules;
use AcademicObligations\ThesisDocuments\Models\ThesisDocumentMetadata;
use Ramsey\Uuid\Uuid;


/**
 * Class ThesisDocumentsStatusChangeRequestAction
 * @package AcademicObligations\ThesisDocuments\Actions\ThesisDocuments
 */
class ThesisDocumentsStatusChangeAction extends Action
{
  use WithValidations;

  /**
   * @param ThesisDocumentsStatusChangeRequest $request
   */
  public function __construct(
    protected ThesisDocumentsStatusChangeRequest $request,
  ) {}

  /**
   * @return void
   */
  public function execute(): void
  {
    DB::transaction(function () {
      $document = GetThesisAction::findOrFail($this->request->thesisDocument());
      $document = $this->validateDocument($document);
      $document->fill($this->request->values());
      $document->save();
      $this->handleStatusChange($document); // Cambiado el nombre para mayor claridad
      $this->updateMetadata($document);
      $this->response(new ThesisDocumentAggregateRootResponse($document));
      $this->raiseEvents($document);
    });
  }

  /**
   * Maneja la creación de un nuevo documento según si fue aprobado o rechazado
   */
  public function handleStatusChange($document)
  {
    $newStatus = $this->getNewStatus();
    if ($newStatus === ThesisDocumentStatusEnum::APPROVED) {
      $this->createThesisBySubThesis($document, true);
    } elseif ($newStatus === ThesisDocumentStatusEnum::REJECTED) {
      $this->createThesisBySubThesis($document, false);
    }
  }

  /**
   * Crea un nuevo registro de thesis_document según si fue aprobado o rechazado
   */
  public function createThesisBySubThesis($document, $aprobado = true)
  {
    $thesisDocument = new ThesisDocument();
    $thesisDocument->uuid = Uuid::uuid4()->toString();
    $thesisDocument->thesis()->associate($document->thesis_id);
    $thesisDocument->student()->associate($document->student_id);

    $thesisMetadata = $this->getStatusThesis($document->thesis_id);

    if ($aprobado) {
      $stateSubThesisMetadata = $this->nextStepThesisDocuments($thesisMetadata);
    } else {
      $stateSubThesisMetadata = $this->sameStepThesisDocuments($thesisMetadata);
    }

    $thesisDocument->status = ThesisDocumentStatusEnum::default();
    $thesisDocument->save();
    $this->updateMetadataNewSubThesis($thesisDocument, $stateSubThesisMetadata);
  }

  /**
   * Devuelve el mismo status y substatus (para rechazos)
   */
  public function sameStepThesisDocuments($thesisMetadata)
  {
    return [
      'thesis_status' => $thesisMetadata->thesis_status,
      'thesis_sub_status' => $thesisMetadata->thesis_sub_status
    ];
  }

  public function nextStepThesisDocuments($thesisMetadata)
  {
    $rules = new Rules();
    $currentStatus = $thesisMetadata->thesis_status;
    $currentSubStatus = $thesisMetadata->thesis_sub_status;

    $getStatePrincipalThesis = $rules->restrictions[$currentStatus] ?? null;
    $getSubStateThesis = isset($getStatePrincipalThesis['subState'][$currentSubStatus])
      ? $getStatePrincipalThesis['subState'][$currentSubStatus]
      : null;

    $nextStepThesis = $getStatePrincipalThesis['nextStatus'] ?? null;
    $nextSubStateThesis = null;
    if (isset($rules->restrictions[$nextStepThesis]['subState']) && is_array($rules->restrictions[$nextStepThesis]['subState'])) {
      $subStates = array_keys($rules->restrictions[$nextStepThesis]['subState']);
      $nextSubStateThesis = $subStates[0] ?? null;
    }

    return [
      'thesis_status' => $nextStepThesis,
      'thesis_sub_status' => $nextSubStateThesis
    ];
  }

  /**
   * Summary of getStatusThesis
   * @param mixed $thesis_id
   * @return ThesisDocumentMetadata
   */
  public function getStatusThesis($thesis_id)
  {
    return ThesisDocumentMetadata::query()
      ->where('thesis_document_id', $thesis_id)
      ->firstOrFail();
  }

  /**
   * Summary of getNewStatus
   * @return ThesisDocumentStatusEnum
   */
  public function getNewStatus(): ThesisDocumentStatusEnum
  {
    return ThesisDocumentStatusEnum::from($this->request->status());
  }

  /**
   * Summary of updateMetadataNewSubThesis
   * @param \AcademicObligations\ThesisDocuments\Models\ThesisDocument $thesisDocument
   * @return void
   */
  public function updateMetadataNewSubThesis(ThesisDocument $thesisDocument,  $thesis)
  {
    $thesisDocument->metadata()->create();
    $thesisDocument->metadata->thesis_status = $thesis['thesis_status'];
    $thesisDocument->metadata->thesis_sub_status = $thesis['thesis_sub_status'];
    $thesisDocument->metadata->save();
  }

  /**
   * @param ThesisDocument $document
   * @return void
   */
  protected function raiseEvents(ThesisDocument $document): void
  {
    // dd($document);
    match ($document->status) {
      ThesisDocumentStatusEnum::APPROVED => RabbitMQMessage::dispatchMessage(
        new ThesisDocumentApprovedEvent($document) // Consultar la logica a implementar
      ),
      ThesisDocumentStatusEnum::REJECTED => RabbitMQMessage::dispatchMessage(
        new ThesisDocumentRejectedEvent($document)
      ),
      default => logger()->info('No events to be raised', $document->only(['uuid', 'status'])),
    };
  }
}
