<?php

namespace AcademicObligations\ThesisNotifications\Events\Listeners\Processors;

use AcademicObligations\ThesisNotifications\Actions\CreateMessageAction;
use AcademicObligations\ThesisNotifications\Actions\SendMailableAction;
use AcademicObligations\ThesisNotifications\Collectors\Exceptions\CollectorException;
use AcademicObligations\ThesisNotifications\Collectors\Interfaces\FileCollector;
use AcademicObligations\ThesisNotifications\Collectors\Interfaces\StudentCollector;
use AcademicObligations\ThesisNotifications\Collectors\Interfaces\ThesisCollector;
use AcademicObligations\ThesisNotifications\DB\Enums\RecipientType;
use AcademicObligations\ThesisNotifications\DB\Enums\ResponsibleType;
use AcademicObligations\ThesisNotifications\DB\Models\Message;
use AcademicObligations\ThesisNotifications\Events\DTOs\DocumentEventsDTO;
use AcademicObligations\ThesisNotifications\Mails\Mailables\ThesisNotificationMail;
use AcademicObligations\ThesisNotifications\Shared\DTOs\RecipientDTO;
use AcademicObligations\ThesisNotifications\Shared\DTOs\StudentDTO;
use AcademicObligations\ThesisNotifications\Shared\DTOs\ThesisResponsibleDTO;
use Illuminate\Support\Collection;
use AcademicObligations\ThesisNotifications\Events\Listeners\Processors\ThesisStateValidator;

/**
 * @package \AcademicObligations\ThesisNotifications\Events\Listeners\Processors
 */
class DocumentUploadedProcessor
{
  public const NOTIFICATION_STUDENT = "thesis.document.uploaded.student";
  public const NOTIFICATION_REVIEWER = "thesis.document.uploaded.reviewer";
  public const NOTIFICATION_DIRECTOR_PTD = 'thesis-document-uploaded-director-ptd';
  public const NOTIFICATION_STUDENT_PTD = 'thesis-document-uploaded-student-ptd';

  public function __construct(
    private StudentCollector $studentCollector,
    private ThesisCollector $thesisCollector,
    private CreateMessageAction $createMessageAction,
    private SendMailableAction $sendMailableAction,
    private FileCollector $fileCollector
  ) {}

  /**
   * @param DocumentEventsDTO $data
   *
   * @return array<Message, ?Message>
   *
   * @throws CollectorException
   */
  public function process(DocumentEventsDTO $data): array
  {
    $student = $this->studentCollector->setUuid($data->student->uuid)->get();
    $thesis = $this->thesisCollector->setUuid($data->thesis->uuid)->get();

    // Procesar notificaciones básicas (estudiante y revisor)
    $studentMsg = $this->buildStudentMessage($student, $thesis->uuid);
    $reviewerMsg = $this->buildReviewerMessage($thesis->responsibles, $thesis->uuid);

    $this->sendMailableAction->execute($studentMsg);

    if ($reviewerMsg) {
      $this->sendMailableAction->execute(
        $reviewerMsg,
        function (ThesisNotificationMail $message) use ($student, $data) {
          $message->mergeData([
            'name' => $student->name,
            'lastName' => $student->lastName,
          ])
            ->attachThesisDocuments($data->attachments)
            ->setCollector($this->fileCollector);
        }
      );
    }

    // Procesar notificaciones específicas según el estado de la tesis
    $additionalMessages = $this->processStateSpecificNotifications($thesis, $student, $data);

    return array_merge([$studentMsg, $reviewerMsg], $additionalMessages);
  }


  /**
   * Procesa notificaciones específicas según el estado de la tesis
   */
  private function processStateSpecificNotifications($thesis, $student, $data): array
  {
    if (ThesisStateValidator::isTD1WithPTD($thesis->status, $thesis->subStatus)) {
      return $this->processTD1PTDNotifications($student, $thesis, $data);
    }

    return [];
  }

  /**
   * Procesa notificaciones específicas para TD1 con PTD
   */
  private function processTD1PTDNotifications($student, $thesis, $data): array
  {
    $studentPTDMsg = $this->buildStudentPTDMessage($student, $thesis->uuid);
    $directorMsg = $this->buildDirectorMessage($thesis->responsibles, $thesis->uuid);

    $this->sendMailableAction->execute($studentPTDMsg);

    if ($directorMsg) {
      $this->sendMailableAction->execute(
        $directorMsg,
        function (ThesisNotificationMail $message) use ($student, $data) {
          $message->mergeData([
            'name' => $student->name,
            'lastName' => $student->lastName,
            'manual' => config('tesis_links.manual'),
            'antiPlagiarism' => config('tesis_links.antiplagiarism')
          ])
            ->attachThesisDocuments($data->attachments)
            ->setCollector($this->fileCollector);
        }
      );
    }

    return [$studentPTDMsg, $directorMsg];
  }

  /**
   * Construye el mensaje para el alumno
   */
  private function buildStudentMessage(StudentDTO $student, string $thesisUuid): Message
  {
    $studentReceiver = new RecipientDTO($student->email, RecipientType::TO);
    return $this->createMessageAction
      ->setNotification(self::NOTIFICATION_STUDENT)
      ->setRecipients(collect([$studentReceiver]))
      ->execute($thesisUuid);
  }

  /**
   * Construye el mensaje para los reviewers
   */
  private function buildReviewerMessage(array $responsibles, string $thesisUuid): ?Message
  {
    $reviewers = collect($responsibles)
      ->filter(fn(ThesisResponsibleDTO $responsible) => $responsible->role === ResponsibleType::REVIEWER)
      ->map(fn(ThesisResponsibleDTO $responsible) => new RecipientDTO($responsible->userReference, RecipientType::TO));

    if ($reviewers->isEmpty()) {
      return null;
    }

    return $this->createMessageAction
      ->setNotification(self::NOTIFICATION_REVIEWER)
      ->setRecipients($reviewers)
      ->execute($thesisUuid);
  }

  /**
   * Construye el mensaje específico para el estudiante en PTD
   */
  private function buildStudentPTDMessage(StudentDTO $student, string $thesisUuid): Message
  {
    $studentReceiver = new RecipientDTO($student->email, RecipientType::TO);
    return $this->createMessageAction
      ->setNotification(self::NOTIFICATION_STUDENT_PTD)
      ->setRecipients(collect([$studentReceiver]))
      ->execute($thesisUuid);
  }

  /**
   * Construye el mensaje para el director
   */
  private function buildDirectorMessage(array $responsibles, string $thesisUuid): ?Message
  {
    $directors = collect($responsibles)
      ->filter(fn(ThesisResponsibleDTO $responsible) => $responsible->role === ResponsibleType::DIRECTOR)
      ->map(fn(ThesisResponsibleDTO $responsible) => new RecipientDTO($responsible->userReference, RecipientType::TO));

    if ($directors->isEmpty()) {
      return null;
    }

    return $this->createMessageAction
      ->setNotification(self::NOTIFICATION_DIRECTOR_PTD)
      ->setRecipients($directors)
      ->execute($thesisUuid);
  }
}
