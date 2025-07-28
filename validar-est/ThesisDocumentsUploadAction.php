<?php

namespace AcademicObligations\ThesisDocuments\Actions\ThesisDocuments;

use AcademicObligations\ThesisDocuments\Actions\Action;
use AcademicObligations\ThesisDocuments\Interface\FilesServiceClientInterface;
use AcademicObligations\ThesisDocuments\Models\Attachment;
use AcademicObligations\ThesisDocuments\Models\DTO\FileAggregateReferenceDTO;
use AcademicObligations\ThesisDocuments\Models\Enums\ThesisDocumentStatusEnum;
use AcademicObligations\ThesisDocuments\Models\Rules;
use AcademicObligations\ThesisDocuments\Models\ThesisDocument;
use AcademicObligations\ThesisDocuments\Services\ThesisDocumentSubStateService;
use AcademicObligations\ThesisDocuments\Requests\ThesisDocuments\ThesisDocumentsUploadRequest;
use AcademicObligations\ThesisDocuments\Responses\ThesisDocumentAggregateRootResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Class ThesisDocumentsUploadAction
 * @package AcademicObligations\ThesisDocuments\Actions\ThesisDocuments
 */
class ThesisDocumentsUploadAction extends Action
{
  use WithValidations;

  /**
   * @param ThesisDocumentsUploadRequest $request
   * @param FilesServiceClientInterface $client
   */
  public function __construct(
    protected ThesisDocumentsUploadRequest $request,
    protected FilesServiceClientInterface $client,
  ) {}

  /**
   * @return void
   */
  public function execute(): void
  {
    DB::transaction(function () {
      $document = GetThesisAction::findOrFail($this->request->thesisDocument());
      $thesis = $document->thesis;

      try {
        $this->validateBusinessRules($thesis, $document, $this->request->attachments());
      } catch (\DomainException $e) {
        abort(422, $e->getMessage());
      }
      // dd($document->status);
      // return;
      $document = $this->validateDocument($document);
      $document->fill($this->request->values());
      $document->attachments()->sync($this->uploadAttachments($document));
      $document->save();
      $this->updateMetadata($document);

      $this->response(new ThesisDocumentAggregateRootResponse($document));
    });
  }

  /**
   * Valida las reglas de negocio según el estado actual de la tesis y el documento
   * @param mixed $thesis
   * @param mixed $document
   * @param array $attachments
   * @return void
   * @throws \DomainException
   */
  private function validateBusinessRules($thesis, $document, array $attachments): void
  {
    $rules = new Rules();
    $currentThesisStatus = $thesis->status;
    // dd($currentThesisStatus);
    $thesisRules = isset($rules->restrictions[$currentThesisStatus]) ? $rules->restrictions[$currentThesisStatus] : null;

    if (!$thesisRules) {
      throw new \DomainException("No se permiten subidas para el estado de tesis: {$currentThesisStatus}");
    }

    $subStateService = new ThesisDocumentSubStateService();
    $subStateRules = $subStateService->getApplicableRules($document);
    // dd($subStateRules);
    if (!$subStateRules) {
      throw new \DomainException("No se permiten subidas para el estado actual del documento");
    }

    $this->validateAttachmentsByRules($attachments, $subStateRules);
  }



  /**
   * Valida los archivos según las reglas específicas del subestado
   * @param array $attachments
   * @param array $subStateRules
   * @return void
   * @throws \DomainException
   */
  private function validateAttachmentsByRules(array $attachments, array $subStateRules)
  {
    // dd($subStateRules);
    // return;
    $files = collect($attachments);
    // dd($files);
    // return;
    $maxFiles = isset($subStateRules['maxFiles']) ? $subStateRules['maxFiles'] : 1;
    $allowedTypes = isset($subStateRules['allowedTypes']) ? $subStateRules['allowedTypes'] : 'pdf';
    $maxSize = 15 * 1025 * 1025;

    if ($files->count() > $maxFiles) {
      throw new \DomainException("Se permite un máximo de {$maxFiles} archivo(s)");
    }

    $allowedTypesArray = explode(',', $allowedTypes);
    // dd($allowedTypesArray);
    // return;
    $fileExtensions = $files->map(function ($attachment) {
      return isset($attachment['file']) ? $attachment['file']->getClientOriginalExtension() : null;
    })->filter();

    // dd($fileExtensions);
    foreach ($fileExtensions as $extension) {
      if (!in_array(strtolower($extension), array_map('strtolower', $allowedTypesArray))) {
        throw new \DomainException("Tipo de archivo no permitido. Tipos permitidos: {$allowedTypes}");
      }
    }

    $maxSizeInBytes = $this->convertSizeToBytes($maxSize);
    foreach ($files as $attachment) {
      if (isset($attachment['file']) && $attachment['file']->getSize() > $maxSizeInBytes) {
        throw new \DomainException("El archivo excede el tamaño máximo permitido de {$maxSize}");
      }
    }
    // dd($maxSizeInBytes);

    // Validaciones específicas para PTDD1 (Propuesta Tesis Doctoral Definitiva) -- REFACTORIZAR PARA QUE SEA DINAMICA
    if (isset($subStateRules['name']) && strpos($subStateRules['name'], 'Definitiva') !== false) {
      $this->validatePTDD1Requirements($files, $allowedTypes);
    }
  }

  /**
   * Valida los requisitos específicos para PTDD1
   * @param Collection $files
   * @return void
   * @throws \DomainException
   */
  private function validatePTDD1Requirements(Collection $files, $allowedTypes)
  {
    // dd($files);
    // return;
    $pdf = $files->first(fn($f) => isset($f['file']) && $f['file']->getClientOriginalExtension() === 'pdf');
    $doc = $files->first(fn($f) => isset($f['file']) && in_array($f['file']->getClientOriginalExtension(), ['doc', 'docx']));

    if (!$pdf || !$doc || $files->count() !== 2) {
      throw new \DomainException("Debe subir los documentos necesarios para poder subir su thesis, que son:\n {$allowedTypes}");
    }
  }

  /**
   * Convierte un tamaño en formato legible a bytes
   * @param string $size
   * @return int
   */
  private function convertSizeToBytes($size)
  {
    $size = strtolower(trim($size));
    $units = ['b' => 1, 'kb' => 1024, 'mb' => 15524 * 1024, 'gb' => 1024 * 1024 * 1024];

    foreach ($units as $unit => $multiplier) {
      if (str_ends_with($size, $unit)) {
        $value = (int) str_replace($unit, '', $size);
        return $value * $multiplier;
      }
    }

    return (int) $size;
  }

  /**
   * Iterates all the attachments and retrieves the FileAggregateReference
   *
   * @return Collection<int, Attachment>
   */
  public function uploadAttachments(ThesisDocument $document): Collection
  {
    return collect($this->request->attachments())->map(
      fn(array $attachment) => ($file = data_get($attachment, 'file')) ?
        $this->uploadAttachment($document, $file, $attachment) :
        $this->createOrUpdateAttachment($document, $attachment)
    );
  }

  /**
   * Sends the uploaded files and retrieves the FileAggregateReference
   *
   * @param ThesisDocument $document
   * @param UploadedFile $file
   * @param array $attachment
   * @return Attachment
   */
  public function uploadAttachment(ThesisDocument $document, UploadedFile $file, array $attachment): Attachment
  {
    $time = now()->format('Y/m');
    $attachment['path'] = "thesis-documents/$time/{$document->student->uuid}";
    $file = $this->client->store($file, $attachment);

    return $this->createOrUpdateAttachment($document, $file->toArray());
  }

  /**
   * Links the current ThesisDocument with an upload files and then retrieves the FileAggregateReference
   *
   * @param ThesisDocument $document
   * @param array $values
   * @return Attachment
   */
  public function createOrUpdateAttachment(ThesisDocument $document, array $values): Attachment
  {
    $file = FileAggregateReferenceDTO::fromArray($values);
    $values['references'] = [
      [
        'uuid' => $document->uuid,
        'entity_type' => class_basename($document),
        'entity_id' => $document->id,
        'service' => config('app.name'),
      ],
    ];
    $file = $this->client->update($file, $values);
    $attachment = Attachment::query()
      ->where('uuid', $file->uuid)
      ->firstOr(fn() => new Attachment());
    $attachment->uuid = $file->uuid;
    $attachment->fill($file->toArray());
    $attachment->save();

    return $attachment;
  }

  /**
   * @return ThesisDocumentStatusEnum
   */
  public function getNewStatus(): ThesisDocumentStatusEnum
  {
    return ThesisDocumentStatusEnum::UPLOADED;
  }
}
