<?php

namespace AcademicObligations\ThesisDocuments\Actions\ThesisDocuments;

use AcademicObligations\ThesisDocuments\Actions\Action;
use AcademicObligations\ThesisDocuments\Interface\FilesServiceClientInterface;
use AcademicObligations\ThesisDocuments\Models\Attachment;
use AcademicObligations\ThesisDocuments\Models\DTO\FileAggregateReferenceDTO;
use AcademicObligations\ThesisDocuments\Models\Enums\ThesisDocumentStatusEnum;
use AcademicObligations\ThesisDocuments\Models\ThesisDocument;
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
    )
    {

    }

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

            $document = $this->validateDocument($document);
            $document->fill($this->request->values());
            $document->attachments()->sync($this->uploadAttachments($document));
            $document->save();
            $this->updateMetadata($document);

            $this->response(new ThesisDocumentAggregateRootResponse($document));
        });
    }

    /**
     * Valida todas las reglas de negocio para la subida de documentos.
     */
    private function validateBusinessRules($thesis, $document, array $attachments): void
    {
        if ($this->isTD1AndAprobado($thesis, $document)) {
            $this->validateTD1Attachments($attachments);
        }
        $this->validateNoDuplicateTD1($document);
    }

    /**
     * Determina si es TD1 y el documento está aprobado.
     */
    private function isTD1AndAprobado($thesis, $document): bool
    {
        return $thesis && $thesis->step === 'TD1' && $document->status === 'APROBADO';
    }

    /**
     * Valida que para TD1 y status APROBADO se suba exactamente un PDF y un DOC/DOCX.
     */
    private function validateTD1Attachments(array $attachments): void
    {
        $files = collect($attachments);
        $pdf = $files->first(fn($f) => isset($f['file']) && $f['file']->getClientOriginalExtension() === 'pdf');
        $doc = $files->first(fn($f) => isset($f['file']) && in_array($f['file']->getClientOriginalExtension(), ['doc', 'docx']));
        if (!$pdf || !$doc || $files->count() !== 2) {
            throw new \DomainException('Debe subir exactamente un archivo PDF y uno DOC o DOCX para la Propuesta Definitiva.');
        }
    }

    /**
     * Valida que no exista ya una propuesta definitiva para este alumno.
     */
    private function validateNoDuplicateTD1($document): void
    {
        $exists = ThesisDocument::query()
            ->where('student_id', $document->student_id)
            ->where('thesis_id', $document->thesis_id)
            ->where('step', 'TD1')
            ->exists();
        if ($exists) {
            throw new \DomainException('Ya existe una Propuesta de Tesis Doctoral Definitiva para este alumno.');
        }
    }

    /**
     * Iterates all the attachments and retrieves the FileAggregateReference
     *
     * @return Collection<int, Attachment>
     */
    public function uploadAttachments(ThesisDocument $document): Collection
    {
        return collect($this->request->attachments())->map(
            fn(array $attachment) => ($file = data_get($attachment, 'file'))?
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

