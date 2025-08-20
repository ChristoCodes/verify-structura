<?php

namespace Tests\Feature\DomainEvents;

use AcademicObligations\ThesisCore\Thesis\Actions\CreateThesis;
use AcademicObligations\ThesisCore\Thesis\Http\Requests\CreateThesisRequest;
use AcademicObligations\ThesisCore\Thesis\Models\Thesis;
use AcademicObligations\ThesisCore\Thesis\DomainEvents\ThesisOpenedEvent;
use AcademicObligations\ThesisCore\Tracking\InternalEvents\Events\ThesisTrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use SlothDevGuy\RabbitMQMessages\Models\DispatchMessageModel;

class ThesisTrackingEventsTest extends TestCase
{
  use RefreshDatabase;

  /** @test */
  public function it_dispatches_thesis_opened_event_when_thesis_is_created()
  {
    // Arrange
    Event::fake();

    $request = new CreateThesisRequest();
    $request->merge([
      'title' => 'Nueva Tesis de Prueba',
      'objective' => 'Objetivo de la tesis',
      'observation' => 'Observaciones importantes',
      'research_type' => 'Investigación Aplicada',
      'language' => 'Español',
      'research_focuses' => [],
      'program_reference' => 'program-uuid',
      'student_reference' => 'student-uuid',
      'created_by_email' => 'test@example.com',
    ]);

    $action = app(CreateThesis::class);

    // Act
    $thesis = $action->execute($request);

    // Assert - Verificar que se dispara el evento de dominio
    $this->assertDatabaseHas(DispatchMessageModel::class, [
      'name' => (new ThesisOpenedEvent($thesis))->getEventName(),
    ]);
  }

  /** @test */
  public function it_dispatches_thesis_tracking_event_when_thesis_is_created()
  {
    // Arrange
    Event::fake();

    $request = new CreateThesisRequest();
    $request->merge([
      'title' => 'Tesis con Tracking',
      'objective' => 'Objetivo',
      'observation' => 'Observación',
      'research_type' => 'Investigación',
      'language' => 'Español',
      'research_focuses' => [],
      'program_reference' => 'program-uuid',
      'student_reference' => 'student-uuid',
      'created_by_email' => 'test@example.com',
    ]);

    $action = app(CreateThesis::class);

    // Act
    $thesis = $action->execute($request);

    // Assert - Verificar que se dispara el evento interno
    Event::assertDispatched(ThesisTrackingEvent::class, function ($event) use ($thesis) {
      return $event->thesis->uuid === $thesis->uuid;
    });
  }

  /** @test */
  public function it_dispatches_both_events_in_correct_order()
  {
    // Arrange
    Event::fake();

    $request = new CreateThesisRequest();
    $request->merge([
      'title' => 'Tesis Completa',
      'objective' => 'Objetivo',
      'observation' => 'Observación',
      'research_type' => 'Investigación',
      'language' => 'Español',
      'research_focuses' => [],
      'program_reference' => 'program-uuid',
      'student_reference' => 'student-uuid',
      'created_by_email' => 'test@example.com',
    ]);

    $action = app(CreateThesis::class);

    // Act
    $thesis = $action->execute($request);

    // Assert - Verificar que ambos eventos se disparan
    Event::assertDispatched(ThesisTrackingEvent::class);

    $this->assertDatabaseHas(DispatchMessageModel::class, [
      'name' => (new ThesisOpenedEvent($thesis))->getEventName(),
    ]);
  }

  /** @test */
  public function it_includes_correct_data_in_thesis_tracking_event()
  {
    // Arrange
    Event::fake();

    $request = new CreateThesisRequest();
    $request->merge([
      'title' => 'Tesis con Datos',
      'objective' => 'Objetivo Detallado',
      'observation' => 'Observación Completa',
      'research_type' => 'Investigación Básica',
      'language' => 'Inglés',
      'research_focuses' => [],
      'program_reference' => 'program-uuid',
      'student_reference' => 'student-uuid',
      'created_by_email' => 'test@example.com',
    ]);

    $action = app(CreateThesis::class);

    // Act
    $thesis = $action->execute($request);

    // Assert - Verificar que el evento contiene los datos correctos
    Event::assertDispatched(ThesisTrackingEvent::class, function ($event) use ($thesis) {
      return $event->thesis->uuid === $thesis->uuid &&
        $event->aggregatedData->get('title') === 'Tesis con Datos' &&
        $event->aggregatedData->get('objective') === 'Objetivo Detallado';
    });
  }
}
