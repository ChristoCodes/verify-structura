<?php

namespace CTDesarrollo\UnicApp\Controllers\Support;

use ADORecordSet_empty;
use CTDesarrollo\Core\Empleados\Models\Delegado;
use CTDesarrollo\Core\Empleados\Models\Responsabilidad;
use CTDesarrollo\Core\EntidadesFormativas\Models\Asignatura;
use CTDesarrollo\Core\EntidadesFormativas\Models\AsignaturaVersion;
use CTDesarrollo\Core\EntidadesFormativas\Models\ElementoAcademico;
use CTDesarrollo\Core\EntidadesFormativas\Models\Programa;
use CTDesarrollo\Core\EntidadesFormativas\Models\ProgramaVersion;
use CTDesarrollo\Core\EntidadesFormativas\Services\AcademicElementNodeFilter;
use CTDesarrollo\Core\EstructurasAcademicas\Models\NodoAcademico;
use CTDesarrollo\Core\EstructurasAcademicas\Repositories\NodosAcademicos;
use CTDesarrollo\Core\Expedientes\Registros\Models\Admision;
use CTDesarrollo\Core\Expedientes\Registros\Models\Matricula;
use CTDesarrollo\Core\Expedientes\Registros\Models\RecordAcademico;
use CTDesarrollo\Core\Expedientes\Registros\Models\SeleccionAcademica;
use CTDesarrollo\Core\Framework\DB\Model;
use CTDesarrollo\Core\OrdenAcademico\Models\Convocatoria;
use CTDesarrollo\Core\OrdenAcademico\Models\GrupoAcademico;
use CTDesarrollo\Core\OrdenAcademico\Models\OfertaAcademica;
use CTDesarrollo\Core\OrdenAcademico\Models\PeriodoLectivo;
use CTDesarrollo\Core\OrdenAcademico\Models\PlazaAcademica;
use CTDesarrollo\Core\OrdenAcademico\Models\RestriccionOfertaGrupo;
use CTDesarrollo\Core\OrdenAcademico\Repositories\PeriodosLectivos;
use CTDesarrollo\CoreApp\Framework\Routing\AppController;
use CTFramework\Framework\Database\ActiveRecord\Collection;
use CTFramework\Framework\System\Loader;
use iio\libmergepdf\Exception;
use CTDesarrollo\UnicApp\Controllers\Support\AcademicTeaching\TransferNotes\TransferNotesAction;

use function class_basename;
use function collect;

/**
 * Resource
 *
 */
class CambioAsignaturasEstructuras extends AppController
{
    private static $response = [
        "steps" => [],
        "errors" => [],
        "status" => true
    ];

    /**
     * @return array
     *
     * @throws \Exception
     */
    public function handleEvent()
    {
        $payload = $this->getRequest()->getParsedBody();

        switch ($payload['event']) {
            case 'add':
                $this->proccessAddSubject($payload['data']);
                break;
            case 'update':
                $this->proccessUpdateSubject($payload['data']);
                break;
            case 'update-programs-subjects':
                $this->proccessUpdatesProgramsAndSubjects($payload['data']);
                break;
            case 'update-academic-records-groups':
                $this->processUpdateAcademicRecordsAnGroupsByAcademicNodes($payload['data']);
                break;
            case 'delete':
                $this->proccessDeleteSubject($payload['data']);
                break;
            case 'verify-groups':
                $this->verifyGroupsUpdate($payload['data']);
                break;
        }

        return self::$response;
    }


    /**
     * @param $data
     *
     * @return void
     *
     * @throws \Exception
     */
    public function proccessAddSubject($data)
    {
        $ProgramaVersion = $this->getVersionProgram($data['program_abbr'], $data['program_version']);
        $AcademicNodeSubject = $this->getAcademicNodeAsignaturaVersionByAbreviature($data['new_subject_abbr'],  $ProgramaVersion);
        $term = $data['term'] ? $this->getTermByName($data['term']) : null;
        $NewCall = $this->getCallByName($data['new_call'], $term);



        /** BEGIN ACADEMIC SELECTION, ACADEMIC SQUARE LOGIC */
        $NewAcademicSquare = PlazaAcademica::findOrCreate($NewCall, $AcademicNodeSubject);
        $Enrollments = $this->getEnrollmentsByProgram($data['program_abbr'], $data['subject_abbr'],  $term);
        $this->createAcademicSelection($Enrollments, $NewCall, $NewAcademicSquare, $AcademicNodeSubject);
    }

    /**
     * @param $data
     *
     * @return void
     *
     * @throws \Exception
     */
    public function proccessUpdateSubject($data)
    {
        $ProgramVersion = $this->getVersionProgram($data['program_abbr'], $data['program_version']);
        $OldAcademicNodeSubject = $this->getAcademicNodeAsignaturaVersionByAbreviature($data['subject_abbr'], $ProgramVersion);
        $NewAcademicNodeSubject = $this->getNewAcademicNodeFromUpdate($data, $ProgramVersion) ?: $OldAcademicNodeSubject;

        $term = $data['term'] ? $this->getTermByName($data['term']) : null;
        $currentCall = $this->getCallByName($data['current_call'], $term);
        $newCall = $this->getCallByName($data['new_call'], $term);

        $OldAcademicSquare = PlazaAcademica::findOrCreate($currentCall, $OldAcademicNodeSubject);
        $NewAcademicSquare = PlazaAcademica::findOrCreate($newCall, $NewAcademicNodeSubject);

        if ($OldAcademicSquare->id === $NewAcademicSquare->id) {
            static::logProccess('Nothing to do. Both squares are the same');
            return;
        }

        $this->createSquareLanguages($OldAcademicSquare, $NewAcademicSquare);

        $Enrollments = $this->getEnrollmentsByProgram($data['program_abbr'], $data['subject_abbr'],$data['lective_period']);
        $oldAcademicGroups = self::searchAcademicGroupsByAcademicSquare($OldAcademicSquare);

        $this->updateAcademicSelection($Enrollments, $OldAcademicNodeSubject, $currentCall, $newCall, $NewAcademicSquare);

        $oldAcademicOffer = $this->findOrCreateAcademicOffer($currentCall, $ProgramVersion->Programa, $OldAcademicNodeSubject);
        $newAcademicOffer = $this->findOrCreateAcademicOffer($newCall, $ProgramVersion->Programa, $NewAcademicNodeSubject);

        $this->updateDistributionOffer($newAcademicOffer, $oldAcademicOffer->distribuido);
        $this->replicateResponsabilityOffer($oldAcademicOffer, $newAcademicOffer);
        $this->insertIfNotExistsAcademicOfferAcademicSquare($newAcademicOffer->id, $NewAcademicSquare->id);

        $groupsInfo = $this->proccessAcademicGroups($oldAcademicGroups, $NewAcademicSquare, $newAcademicOffer, true);
        $oldAcademicGroups = $groupsInfo['oldAcademicGroups'];
        $newAcademicGroups = $groupsInfo['newAcademicGroups'];;
        $matchedGroups = $groupsInfo['matchedGroups'];;

        $this->proccessOtherRestrictionsOfOffer($newAcademicOffer, array_map(function($group){ return $group->id; }, $newAcademicGroups));
        $this->changeGroupsOfSelections($NewAcademicSquare, $matchedGroups);

        $this->updateTotalsOfGroupsRestrictionsOffers($oldAcademicGroups, $oldAcademicOffer);
        $this->deleteOldOffer($oldAcademicOffer);
        $this->deleteDelegatesGroups($oldAcademicGroups);

        $this->updateTotalsOfGroupsRestrictionsOffers($newAcademicGroups, $newAcademicOffer);
    }

    /**
     * @param $data
     *
     * @return void
     *
     * @throws \Exception
     */
    public function proccessDeleteSubject($data)
    {
        $ProgramVersion = $this->getVersionProgram($data['program_abbr'], $data['program_version']);
        $AcademicNodeSubject = $this->getAcademicNodeAsignaturaVersionByAbreviature($data['subject_abbr'], $ProgramVersion);
        $term = $data['term'] ? $this->getTermByName($data['term']) : null;
        $CurrentCall = $this->getCallByName($data['current_call'], $term);
        $AcademicSquare = $this->getAcademicSquare($AcademicNodeSubject, $CurrentCall);
        $AcademicSelections = $this->getAcademicSelections($AcademicSquare);
        $AcademicOffer = self::findOrCreateAcademicOffer($CurrentCall, $ProgramVersion->Programa, $AcademicNodeSubject);
        $AcademicGroups = self::searchAcademicGroupsByAcademicSquare($AcademicSquare);

        $this->deleteAcademicSelection($AcademicSelections);
        $this->updateTotalsOfGroupsRestrictionsOffers($AcademicGroups, $AcademicOffer);
        $this->deleteOldOffer($AcademicOffer);

        $this->deleteDelegatesGroups($AcademicGroups->getArrayCopy());
    }

    /**
     * @param array $data
     * @param ProgramaVersion $ProgramVersion
     *
     * @return NodoAcademico|null
     */
    public function getNewAcademicNodeFromUpdate($data, ProgramaVersion $ProgramaVersion)
    {
        $new_subjetct_abbr = data_get($data, 'new_subject_abbr', $data['subject_abbr']);

        if ($new_subjetct_abbr === $data['subject_abbr']) return null;

        return $this->getAcademicNodeAsignaturaVersionByAbreviature($new_subjetct_abbr, $ProgramaVersion);
    }

    /**
     * @param $abbr
     * @param ProgramaVersion $ProgramVersion
     *
     * @return NodoAcademico
     *
     * @throws \Exception
     */
    public function getAcademicNodeAsignaturaVersionByAbreviature($abbr, ProgramaVersion $ProgramVersion)
    {

        $AsignaturaVersion = $this->getSubjectVersionByAbbreviature($abbr);

        return NodosAcademicos::obtenerNodoAcademicoPorProgramaVersionYAsignaturaVersion(
            $ProgramVersion, $AsignaturaVersion)->first();
    }


    /**
     * Get Academic Node of subject by abbreviation
     *
     * @param string $abbr
     *
     * @return NodoAcademico
     */
    public function getExtraordinaryAcademicNodeBySubject($abbr)
    {
        return NodoAcademico::buscar([
            'ElementoAcademico@AsignaturaVersion' => [
                'Asignatura' => [
                    'abreviatura|%%' => $abbr,
                    'nacceso' => 'publico'
                ],
                'version|%%' => 'vce',
                'nacceso|!' => 'borrador'
            ]
        ], [ 'unscoped' => true ])->first();
    }

    /**
     * @param $abreviatura
     * @param $version
     *
     * @return ProgramaVersion
     *
     * @throws \Exception
     */
    public function getVersionProgram($abreviatura, $version)
    {
        return Loader::invokeClass('ProgramaVersion', 'buscar', [[
            'version' => $version,
            'Programa' => [
                'abreviatura' => $abreviatura,

            ],
        ], []])->first();

    }

    /**
     * @param $abreviatura
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getSubjectVersionByAbbreviature($abreviatura)
    {
        return Loader::invokeClass('AsignaturaVersion', 'buscar', [[
            'Asignatura' => [
                'abreviatura' => $abreviatura,

            ],
            'nacceso|!' => 'privado'
        ], []])->first();

    }

    public function getExtraordinarySubjectVersionByAbbreviature($abreviatura)
    {
        return AsignaturaVersion::buscar([
            'version|%%' => '-Vce',
            'Asignatura' => [
                'abreviatura' => $abreviatura
            ]
        ],[])->first();
    }

    /**
     * @param $name
     *
     * @return Model|Collection|\CTFramework\Framework\Database\ActiveRecord\Model|mixed|null
     */
    public function getTermByName($name)
    {
        return PeriodoLectivo::find([
            'where' => "nombre = '$name' AND nacceso = 'publico'"
        ])->first();
    }

    /**
     * @param $announcementName
     * @param PeriodoLectivo|null $term
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getCallByName($announcementName, PeriodoLectivo $term = null)
    {
        if(!$term)
            $term = PeriodosLectivos::getPeriodoLectivoDisponibleDefaultByClass('Matricula');

        return Loader::invokeClass('Convocatoria', 'buscar', [[
            'nombre' => $announcementName,
            'periodo_lectivo_id' => $term->id,
            'tipo' => '_SEMESTRE',
            'ciclo' => '_ORDINARIO',
            'nacceso' => 'publico',
        ], []])->first();

    }

    /**
     * @param $Node
     * @param $Call
     *
     * @return PlazaAcademica
     *
     * @throws \Exception
     */
    public function getAcademicSquare($Node, $Call)
    {

        $fields = array('class_id' => $Node->id,
            'class' => class_basename($Node),
            'convocatoria_id' => $Call->id,
            'nacceso' => 'publico');

        return $this->Loader->invoke('PlazaAcademica', 'buscar', [$fields, []])->first();


    }

    /**
     * @param $AcademicSquare
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getAcademicSelections($AcademicSquare)
    {

        $fields = array('plaza_academica_id' => $AcademicSquare->id,
            'nacceso' => 'publico');

        return $this->Loader->invoke('SeleccionAcademica', 'buscar', [$fields, []]);


    }

    /**
     * Metodo que retorna todas las inscripciones por programa
     *
     * @param $programAbbreviation
     * @param $subjectAbbreviation
     * @param PeriodoLectivo|null $term
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getEnrollmentsByProgram($programAbbreviation, $subjectAbbreviation,  PeriodoLectivo $term = null)
    {
        if(!$term)
        $term = PeriodosLectivos::getPeriodoLectivoDisponibleDefaultByClass('Matricula');

        $Enrollments = $this->Loader->invoke('Matricula', 'buscar', [[
            'periodo_lectivo_id' => $term->id,
            'Inscripcion' => [
                'Persona' => [
                ],
                'ProgramaVersion' => [
                    'Programa' => [
                        'abreviatura' => $programAbbreviation,
                    ]
                ],
            ],
            'Admisiones' => [
                'SeleccionesAcademicas' => [
                    'PlazaAcademica' => [
                        'NodoEducativo@NodoAcademico' => [
                            'class' => 'AsignaturaVersion',
                            'abreviatura|=' => $subjectAbbreviation,
                        ],
                    ],
                ],
            ],
        ], [], false]);

        return $Enrollments;
    }

    /**
     * @param PlazaAcademica $OldSquare
     * @param PlazaAcademica $NewSquare
     *
     * @return void
     */
    public function createSquareLanguages(PlazaAcademica $OldSquare, PlazaAcademica $NewSquare)
    {
        $LanguagesIds = $OldSquare->Idiomas->ids();
        $status = $NewSquare->set_Idiomas($LanguagesIds);

        static::logProccess("Replicando idiomas de antigua plaza [$OldSquare->id] a nueva plaza [$NewSquare->id]", !!$status);
    }

    /**
     * Crea la seleccion Academica
     *
     * @param $Enrollments
     * @param $Call
     * @param $AcademicSquare
     * @param NodoAcademico $NodeAcademic
     *
     * @return void
     */
    public function createAcademicSelection($Enrollments, $Call, $AcademicSquare, NodoAcademico $NodeAcademic)
    {
        if ($Enrollments->rows_total > 0) {
            foreach ($Enrollments as $Enrollment) {
                $existeSeleccionAcademica = $this->existeSeleccionAcademicaEnEstructura($NodeAcademic->id, $Enrollment);
                if (!$existeSeleccionAcademica) {
                    $Admission = Admision::getAdmisionPorConvocatoria($Enrollment, $Call);
                    $SeleccionAcademica = new SeleccionAcademica();
                    $SeleccionAcademica->admision_id = $Admission->id;
                    $SeleccionAcademica->veces = 1;
                    $SeleccionAcademica->plaza_academica_id = $AcademicSquare->id;
                    $SeleccionAcademica->estado_id = $SeleccionAcademica->getEstadoDefault()->id;
                    $status = $SeleccionAcademica->guardar();
                    static::logProccess("Agregando Seleccion [{$SeleccionAcademica->id}]", $status);
                } else {
                    static::logProccess("Ya Existe Seleccion Academica [{$SeleccionAcademica->id}]");
                }
            }
        } else {
            echo "No hay inscripciones con esa asignatura a agregar.";
        }
    }

    /**
     * Metodo que verifica si existe la seleccion academica.
     *
     * @param $nodoAcademicoId
     * @param $Enrollment
     *
     * @return bool
     */
    public function existeSeleccionAcademicaEnEstructura($nodoAcademicoId, $Enrollment)
    {
        $existsAcademicSelection = false;

        $SeleccionAcademica = Matricula::buscar([
            'Inscripcion' => [ 'id' => $Enrollment->inscripcion_id],
            'Admisiones' => [
                'SeleccionesAcademicas' => [
                    'PlazaAcademica' => [
                        'NodoEducativo@NodoAcademico' => [
                            'id' => $nodoAcademicoId
                        ],
                    ],
                ],
            ],
        ]);

        if($SeleccionAcademica->rows_total > 0)
            $existsAcademicSelection = true;

        return $existsAcademicSelection;
    }


    /**
     * @param $AcademicSeletions
     *
     * @return void
     */
    public function deleteAcademicSelection($AcademicSeletions)
    {
        if ($AcademicSeletions->count() > 0) {
            /** @var SeleccionAcademica $AcademicSeletion */
            foreach ($AcademicSeletions as $AcademicSeletion) {
                if ($AcademicSeletion->id) {
                    $status = $AcademicSeletion->update([
                        'nacceso' => 'borrador',
                    ]);
                    static::logProccess("Eliminando Seleccion [{$AcademicSeletion->id}]", $status);
                }
            }
        }
    }


    /**
     * @param $Enrollments
     * @param $AcademicNodeSubject
     * @param $OldCall
     * @param $NewCall
     * @param $NewAcademicSquare
     *
     * @return void
     */
    public function updateAcademicSelection($Enrollments, $AcademicNodeSubject, $OldCall, $NewCall, $NewAcademicSquare)
    {

        if ($Enrollments->count() > 0) {
            foreach ($Enrollments as $Enrollment) {
                $Admission = Admision::getAdmisionPorConvocatoria($Enrollment, $OldCall);
                $NewAdmission = Admision::getAdmisionPorConvocatoria($Enrollment, $NewCall);

                if($Admission->id){
                    $AcademicSelection = SeleccionAcademica::getSeleccionAcademicaPorAdmision($Admission, $AcademicNodeSubject->id);

                    if ($AcademicSelection->id) {
                        $status = $AcademicSelection->update([
                            'plaza_academica_id' => $NewAcademicSquare->id,
                            'admision_id' => $NewAdmission->id,
                            'fecha_modificacion' => date('Y-m-d H:i:s')
                        ]);

                        static::logProccess(
                            "Actualizando admision y plaza de seleccion [{$AcademicSelection->id}] => [{$NewAdmission->id}], [{$NewAcademicSquare->id}]",
                            $status);
                    }
                }
                continue;
            }
        }
    }

    /**
     * @param Convocatoria $call
     * @param Programa $program
     * @param NodoAcademico $subjectNode
     *
     * @return Model|OfertaAcademica|Collection|mixed|null
     */
    public static function findOrCreateAcademicOffer(Convocatoria $call, Programa $program, NodoAcademico $subjectNode)
    {
        $offer = OfertaAcademica::find([
            'where' => "convocatoria_id = {$call->id} AND programa_id = {$program->id} 
                        AND class = 'AsignaturaVersion' AND class_id = {$subjectNode->ElementoAcademico->id}
                        AND nacceso = 'publico'"
        ])->first();
        if(!$offer)
            $offer = OfertaAcademica::create($call, $program, $subjectNode->getElementoAcademico());

        return $offer;
    }

    /**
     * @param OfertaAcademica $oldAcademicOffer
     *
     * @return void
     */
    private function deleteOldOffer(OfertaAcademica $oldAcademicOffer)
    {
        $status = $this->softDeleteRestrictionsFromOfferOrGroup($oldAcademicOffer);
        static::logProccess("Eliminando restricciones con oferta [{$oldAcademicOffer->id}]", $status);

        $status = $oldAcademicOffer->update_columns([
            'fecha_modificacion' => date('Y-m-d H:i:s'),
            'nacceso' => 'borrador'
        ]);
        static::logProccess("Eliminando antigua oferta academica [{$oldAcademicOffer->id}]", $status);
    }

    /**
     * @param $academicGroups
     *
     * @return void
     */
    private function deleteDelegatesGroups($academicGroups)
    {
        /** @var GrupoAcademico $group */
        foreach($academicGroups as $group){
            if($group->total_alumnos === 0){
                $responsabilities = $group->Responsabilidades([
                    'where' => "rol_id in (35, 36) and nacceso = 'publico'"
                ])->getArrayCopy();

                /** @var Responsabilidad $responsability */
                foreach($responsabilities as $responsability){
                    /** @var Delegado $delegate */
                    foreach($responsability->Delegados->getArrayCopy() as $delegate){
                        $status = $delegate->update_column('activo', 0);
                        static::logProccess("Desactivando delegado [{$delegate->id}]", $status);
                    }
                }
                $status = $responsability->update_column('nacceso', 'borrador');
                static::logProccess("Eliminando responsabilidad [{$responsability->id}]", $status);

                $status = $this->softDeleteRestrictionsFromOfferOrGroup($group);
                static::logProccess("Eliminando restricciones de grupo vacio [$group->id]", $status);

                $status = $group->update_column('nacceso', 'borrador');
                static::logProccess("Eliminando grupo con 0 alumnos [{$group->id}]", $status);
            }

        }

    }

    /**
     * @param OfertaAcademica $offer
     * @param $isDistributed
     *
     * @return void
     */
    private function updateDistributionOffer(OfertaAcademica $offer, $isDistributed)
    {
        $status = $offer->update_column('distribuido', $isDistributed);
        static::logProccess("Actualizando 'distribuido' de nueva oferta [{$offer->id}] a [$isDistributed]", $status);
    }

    private function replicateResponsabilityOffer(OfertaAcademica $oldOffer, OfertaAcademica  $newOffer)
    {
        $responsabilities = $oldOffer->Responsabilidades([
            'where' => "rol_id = 36 and nacceso = 'publico'"
        ]);

        foreach($responsabilities as $currentResponsability){
            $newResponsability = new Responsabilidad();
            $newResponsability->_set([
                'class_id' => $newOffer->id,
                'class' => 'OfertaAcademica',
                'rol_id' => 36,
                'nacceso' => 'publico'
            ]);

            $status = $newResponsability->save() &&
                $newResponsability->update_columns([
                    'fecha_creacion' => $currentResponsability->fecha_creacion,
                    'fecha_modificacion' => $currentResponsability->fecha_modificacion
                ]);

            static::logProccess("Clonando responsabilidad [{$currentResponsability->id}] a [{$newResponsability->id}]", $status);
            if(!$status)
                continue;

            foreach($currentResponsability->Delegados as $delegate){
                $newDelegate = new Delegado();
                $newDelegate->_set([
                    'responsabilidad_id' => $newResponsability->id,
                    'empleado_id' => $delegate->Empleado->id,
                    'usuario_delegante_id' => $delegate->UsuarioDelegante->id,
                    'usuario_finalizador_id' => $delegate->UsuarioFinalizador->id,
                    'activo' => $delegate->activo,
                    'fecha_asignacion' => $delegate->fecha_asignacion,
                    'porcentaje' => $delegate->porcentaje,
                    'nacceso' => 'publico'
                ]);
                $status = $newDelegate->save() &&
                    $newDelegate->update_columns([
                        'fecha_creacion' => $delegate->fecha_creacion,
                        'fecha_modificacion' => $delegate->fecha_modificacion
                    ]);

                static::logProccess("Clonando delegado [{$delegate->id}] a [{$newDelegate->id}]", $status);
            }
        }
    }

    /**
     * @param $offerId
     * @param $squareId
     *
     * @return void
     */
    private function insertIfNotExistsAcademicOfferAcademicSquare($offerId, $squareId)
    {
        $conn = Model::_db();

        $sqlSearch = "SELECT * from oferta_academica_plaza_academica 
                            WHERE oferta_academica_id = $offerId AND plaza_academica_id = $squareId";

        if(count($conn->execute($sqlSearch)->GetArray()) > 0){
            static::logProccess(
                "Relacion Plaza [{$squareId}] - Oferta [{$offerId}] ya existe",
                true
            );
        }else{
            $sqlInsert = "INSERT INTO oferta_academica_plaza_academica(oferta_academica_id, plaza_academica_id) 
                    VALUES ($offerId, $squareId)";

            $result = $conn->execute($sqlInsert);
            static::logProccess(
                "Creando relacion oferta-plaza. Plaza [{$squareId}] - Oferta [{$offerId}]",
                !!$result
            );
        }



    }

    /**
     * @param Collection $academicGroups
     * @param PlazaAcademica $newAcademicSquare
     * @param OfertaAcademica $newAcademicOffer
     * @param bool $includeSchedule
     *
     * @return array
     */
    private function proccessAcademicGroups($academicGroups, PlazaAcademica $newAcademicSquare,OfertaAcademica $newAcademicOffer, $includeSchedule = false)
    {
        //$academicGroups = $this->searchAcademicGroupsByAcademicSquare($newAcademicSquare);
        $newAcademicGroups = [];
        $matchedGroups = [];

        $offersInNewCall = $this->searchOffersByCallElement($newAcademicSquare->Convocatoria, $newAcademicSquare->NodoEducativo->ElementoAcademico);

        /** @var GrupoAcademico $academicGroup */
        foreach($academicGroups as $academicGroup){
            $newAcademicGroup = $this->searchOrCreateAcademicGroupInOffer($academicGroup, $newAcademicOffer, $includeSchedule);

            static::logProccess("Buscando o creando grupo academico... Se devuelve [{$newAcademicGroup->id}]", $newAcademicGroup->id > 0);
            if(!$newAcademicGroup)
                continue; 

            $newAcademicGroups[] = $newAcademicGroup;
            $matchedGroups[$academicGroup->id] = $newAcademicGroup->id;

            if($this->groupHasRestrictionInOffer($newAcademicGroup, $newAcademicOffer)) continue;

            /** @var Responsabilidad $currentResponsability */
            $currentResponsability = $academicGroup->Responsabilidades([
                'where' => "rol_id in (35) AND nacceso = 'publico'"
            ])->first();

            $newResponsability = new Responsabilidad();
            $newResponsability->_set([
                'class_id' => $newAcademicGroup->id,
                'class' => 'GrupoAcademico',
                'rol_id' => $currentResponsability->Rol->id,
                'nacceso' => 'publico'
            ]);

            $status = $newResponsability->save() &&
                $newResponsability->update_columns([
                    'fecha_creacion' => $currentResponsability->fecha_creacion,
                    'fecha_modificacion' => $currentResponsability->fecha_modificacion
                ]);

            static::logProccess("Clonando responsabilidad [{$currentResponsability->id}] a [{$newResponsability->id}]", $status);
            if(!$status)
                continue;

            foreach($currentResponsability->Delegados as $delegate){
                $newDelegate = new Delegado();
                $newDelegate->_set([
                    'responsabilidad_id' => $newResponsability->id,
                    'empleado_id' => $delegate->Empleado->id,
                    'usuario_delegante_id' => $delegate->UsuarioDelegante->id,
                    'usuario_finalizador_id' => $delegate->UsuarioFinalizador->id,
                    'activo' => $delegate->activo,
                    'fecha_asignacion' => $delegate->fecha_asignacion,
                    'porcentaje' => $delegate->porcentaje,
                    'nacceso' => 'publico'
                ]);
                $status = $newDelegate->save() &&
                    $newDelegate->update_columns([
                        'fecha_creacion' => $delegate->fecha_creacion,
                        'fecha_modificacion' => $delegate->fecha_modificacion
                    ]);

                static::logProccess("Clonando delegado [{$delegate->id}] a [{$newDelegate->id}]", $status);
            }

            foreach($offersInNewCall as $offer){
                $newRestriction = new RestriccionOfertaGrupo();
                $newRestriction->_set([
                    'grupo_academico_id' => $newAcademicGroup->id,
                    'oferta_academica_id' => $offer->id,
                    'activo' => 1,
                    'total_alumnos' => 0
                ]);

                $status = $newRestriction->save();
                static::logProccess(
                    "Creando restriccion con oferta [{$offer->id}] y grupo [{$newAcademicGroup->id}]",
                    $status
                );
            }
        }

        return [
            'oldAcademicGroups' => $academicGroups,
            'newAcademicGroups' => $newAcademicGroups,
            'matchedGroups' => $matchedGroups
        ];
    }

    private function proccessOtherRestrictionsOfOffer(OfertaAcademica $offer, $excludedGroupsIds)
    {
        $commaSeparatedGroups = implode(",", $excludedGroupsIds);
        $groups = GrupoAcademico::find([
            'joins' => [
                'restriccion_oferta_grupo' => [
                    'grupo_academico_id' => 'id',
                    'oferta_academica' => [
                        'id' => 'oferta_academica_id'
                    ]
                ]
            ],
            'where' => "grupo_academico.id NOT IN ($commaSeparatedGroups)
                        AND oferta_academica.convocatoria_id = {$offer->Convocatoria->id}
                        AND oferta_academica.class = '". class_basename($offer->ElementoAcademico) ."'
                        AND oferta_academica.class_id = {$offer->ElementoAcademico->id}",
            'group_by' => "grupo_academica.id"
        ]);

        /** @var GrupoAcademico $group */
        foreach ($groups as $group){
            if(!$this->groupHasRestrictionInOffer($group, $offer)){
                $newRestriction = new RestriccionOfertaGrupo();
                $newRestriction->_set([
                    'grupo_academico_id' => $group->id,
                    'oferta_academica_id' => $offer->id,
                    'activo' => 1,
                    'total_alumnos' => 0
                ]);

                $status = $newRestriction->save();
                static::logProccess(
                    "Creando restriccion con oferta [{$offer->id}] y grupo [{$group->id}]",
                    $status
                );
            }
        }
    }

    /**
     * @param PlazaAcademica $academicSquare
     * @param $matchedGroups
     *
     * @return void
     */
    private function changeGroupsOfSelections(PlazaAcademica $academicSquare, $matchedGroups)
    {
        foreach($academicSquare->SeleccionesAcademicas as $academicSelection){
            foreach($academicSelection->GruposAcademicos as $group){
                if(array_key_exists($group->id, $matchedGroups)){
                    $status = $this->updateGroupOfAcademicSelection($matchedGroups[$group->id], $academicSelection->id);
                    static::logProccess("Actualizando relacion seleccion-grupo ([{$academicSelection->id}]-[{$matchedGroups[$group->id]}])", !!$status);
                }
            }
        }
    }

    /**
     * @param $groups
     *
     * @return void
     *
     * @throws Exception
     */
    private function updateTotalsOfGroupsRestrictionsOffers($groups, OfertaAcademica $offer)
    {
        /** @var GrupoAcademico $group */
        foreach($groups as $group){
            $destined_totals = $this->recalculateTotals($offer, $group);
            static::logProccess(
                "Recalculo de totales para grupo [{$group->id}] con oferta [{$offer->id}]. ".
                "group ({$destined_totals->get('total_students_group')}), ".
                "offer({$destined_totals->get('total_students_offer')}), ".
                "restriction ({$destined_totals->get('total_students_restriction')})",
                true
            );
        }
    }

    /**
     * @param PlazaAcademica $academicSquare
     *
     * @return Model|Collection|null
     */
    public static function searchAcademicGroupsByAcademicSquare(PlazaAcademica  $academicSquare)
    {
        return GrupoAcademico::find([
            'joins' => [
                'seleccion_academica_grupo_academico' => [
                    'grupo_academico_id' => 'id',
                    'seleccion_academica' => [
                        'id' => 'seleccion_academica_id',
                    ]
                ]
            ],
            'where' => "seleccion_academica.plaza_academica_id = {$academicSquare->id}",
            'group_by' => 'grupo_academico.id'
        ]);
    }

    private function groupHasRestrictionInOffer(GrupoAcademico $group, OfertaAcademica $offer)
    {
        foreach($group->RestriccionesOfertaGrupo as $restriction){
            if($restriction->oferta_academica_id === $offer->id){
                return true;
            }
        }
        return false;
    }

    /**
     * @param GrupoAcademico $group
     * @param OfertaAcademica $offer
     * @param bool $includeScheduleType
     *
     * @return Model|GrupoAcademico|Collection|\CTFramework\Framework\Database\ActiveRecord\Model|mixed|null
     */
    public function searchOrCreateAcademicGroupInOffer(GrupoAcademico $group, OfertaAcademica $offer, $includeScheduleType = false)
    {
        $scheduleFilter =  ($includeScheduleType && $group->schedule_type_id)
            ? "AND grupo_academico.schedule_type_id = {$group->schedule_type_id}"
            : "";

        $groupInOffer = GrupoAcademico::find([
            'joins' => [
                'restriccion_oferta_grupo' => [
                    'grupo_academico_id' => 'id',
                ]
            ],
            'where' => "grupo_academico.nombre = '{$group->nombre}' 
                        AND restriccion_oferta_grupo.oferta_academica_id = {$offer->id}
                        AND grupo_academico.nacceso = 'publico'
                        {$scheduleFilter}
                        AND restriccion_oferta_grupo.nacceso = 'publico'"
        ])->first();

        if(!($groupInOffer->id > 0)){
            $newGroup = new GrupoAcademico();
            $newGroupData = [
                'grupo_academico_padre'    => 0,
                'nombre'                   => $group->nombre,
                'status'                   => '_ACTIVO',
                'modo'                     => $group->nombre,
                'idioma_id'                => 0,
                'tipo'                     => $group->tipo,
                'categoria'                => $group->categoria,
                'total_alumnos'            => 0,
                'porcentaje_imparticion'   => $group->porcentaje_imparticion,
            ];

            if ($includeScheduleType)
                $newGroupData['schedule_type_id'] = $group->schedule_type_id;

            $newGroup->_set($newGroupData);
            return $newGroup->save() ? $newGroup : null;
        }else{
            return $groupInOffer;
        }
    }

    /**
     * @param Convocatoria $call
     * @param ElementoAcademico $academicElement
     *
     * @return Model|Collection|\CTFramework\Framework\Database\ActiveRecord\Model|null
     */
    private function searchOffersByCallElement(Convocatoria $call, ElementoAcademico $academicElement)
    {
        return OfertaAcademica::find([
            'where' => "convocatoria_id = {$call->id} 
                            AND class = '". class_basename($academicElement) ."'
                            AND class_id = {$academicElement->id}
                            AND nacceso = 'publico'"
        ]);
    }

    /**
     * @param OfertaAcademica|GrupoAcademico $model
     *
     * @return bool
     */
    private function softDeleteRestrictionsFromOfferOrGroup($model)
    {
        foreach($model->RestriccionesOfertaGrupo as $restriction){
            $restriction->set_nacceso('borrador');
            $restriction->fecha_modificacion = date('Y-m-d H:i:s');

            if(!$restriction->save())
                return false;
        }
        return true;
    }

    /**
     * @param $academicGroupId
     * @param $academicSelectionId
     *
     * @return ADORecordSet_empty|false|\RecordSet|\the|null
     */
    private function updateGroupOfAcademicSelection($academicGroupId, $academicSelectionId)
    {
        $conn = Model::_db();
        $sql = "UPDATE seleccion_academica_grupo_academico SET grupo_academico_id = $academicGroupId WHERE seleccion_academica_id = $academicSelectionId";

        return $conn->execute($sql);
    }

    private function calculateAcademicSelectionsByProgramId(GrupoAcademico $academicGroup, $programId)
    {
        $sql = "SELECT count(sa.id) as cantidad FROM seleccion_academica_grupo_academico sg
                    INNER JOIN seleccion_academica sa ON sg.seleccion_academica_id = sa.id
                    INNER JOIN admision ad ON sa.admision_id = ad.id
                    INNER JOIN matricula ma ON ad.matricula_id = ma.id
                    INNER JOIN inscripcion i ON ma.inscripcion_id = i.id
                    INNER JOIN programa_version pv ON i.programa_version_id = pv.id
                    WHERE pv.programa_id = $programId AND sg.grupo_academico_id = {$academicGroup->id}
                        AND sa.nacceso = 'publico'";

        return intval(Model::_db()->execute($sql)->GetArray()[0]['cantidad']);
    }

    private function calculateTotalOfOffer(OfertaAcademica $offer)
    {
        $sql = "SELECT sum(rog.total_alumnos) as cantidad 
                    FROM restriccion_oferta_grupo rog 
                    WHERE oferta_academica_id = {$offer->id}
                        AND rog.nacceso = 'publico'";
        return intval(Model::_db()->execute($sql)->GetArray()[0]['cantidad']);
    }

    /**
     * Recalcule totals of OfertaAcademica, GrupoAcademico and RestriccionGrupoOferta based in offer and group
     *
     * @param OfertaAcademica $academicOffer
     * @param GrupoAcademico $academicGroup
     *
     * @return \Illuminate\Support\Collection
     *
     * @throws Exception|\Exception
     */
    public function recalculateTotals(OfertaAcademica $academicOffer, GrupoAcademico $academicGroup)
    {
        $totalSelectionsByGroup = count ($academicGroup->SeleccionesAcademicas);
        $academicGroup->total_alumnos =  $totalSelectionsByGroup;
        $academicGroup->save();

        /** @var RestriccionOfertaGrupo $restriction */
        $restriction = RestriccionOfertaGrupo::search([
            class_basename($academicGroup) => [
                'id' => $academicGroup->id(),
            ],
            class_basename($academicOffer) => [
                'id' => $academicOffer->id(),
            ]
        ])->get()->first();
        
        if($restriction){
            $total = $this->calculateAcademicSelectionsByProgramId($academicGroup, $academicOffer->programa_id);
            $restriction->total_alumnos = $total;
            $restriction->cantidad_prevision_alumnos = $total;
            $restriction->save();
        }else{
            static::$response['errors'][] = "Failed on restriction of " . class_basename($academicGroup) .  " [{$academicGroup->id}] and ". class_basename($academicOffer) ." [{$academicOffer->id}]";
        }

        $amount = $this->calculateTotalOfOffer($academicOffer);
        $academicOffer->total_alumnos = $amount;
        $academicOffer->save();

        return collect([
            'total_students_offer' => $amount,
            'total_students_group' => $academicGroup->total_alumnos,
            'total_students_restriction' => isset($total) ? $total : '-'
        ]);
    }

    /**
     * @param $step
     * @param $status
     *
     * @return void
     */
    public static function logProccess($step, $status = true){
        static::$response["steps"][] = "$step|$status";
        static::$response["status"] = static::$response["status"] && $status;

        if(!$status){
            static::$response["errors"][] = $step;
        }
    }

    /**
     * Actualiza las estructuras de programas con la misma
     * versión, semestre y periodo.
     *
     * @param $data
     * @return void
     *
     * @throws \Exception
     */
    public function proccessUpdatesProgramsAndSubjects($data)
    {
        $program_abbr = $data['program_abbr'];
        $term = $data['term'] ? $this->getTermByName($data['term']) : null;

        foreach ($program_abbr as $programAbrv)
        {
            if ($data['current_call'] == 'Extraordinaria' && $data['new_call'] == 'Extraordinaria') {
                $this->proccessUpdatesProgramsAndSubjectsExtraordinaria($programAbrv, $data, $term);
            } else {
                $ProgramVersion = $this->getVersionProgram($programAbrv, $data['program_version']);
                $OldAcademicNodeSubject = $this->getAcademicNodeAsignaturaVersionByAbreviature($data['subject_abbr'], $ProgramVersion);
                $NewAcademicNodeSubject = $this->getNewAcademicNodeFromUpdate($data, $ProgramVersion) ?: $OldAcademicNodeSubject;

                $currentCall = $this->getCallByName($data['current_call'], $term);
                $newCall = $this->getCallByName($data['new_call'], $term);

                $OldAcademicSquare = PlazaAcademica::findOrCreate($currentCall, $OldAcademicNodeSubject);
                $NewAcademicSquare = PlazaAcademica::findOrCreate($newCall, $NewAcademicNodeSubject);

                if ($OldAcademicSquare->id === $NewAcademicSquare->id) {
                    static::logProccess('Nothing to do. Both squares are the same');
                    return;
                }

                $this->createSquareLanguages($OldAcademicSquare, $NewAcademicSquare);

                $Enrollments = $this->getEnrollmentsByProgram($programAbrv, $data['subject_abbr'], $term);
                $oldAcademicGroups = self::searchAcademicGroupsByAcademicSquare($OldAcademicSquare);

                $this->updateAcademicSelection($Enrollments, $OldAcademicNodeSubject, $currentCall, $newCall, $NewAcademicSquare);

                $oldAcademicOffer = $this->findOrCreateAcademicOffer($currentCall, $ProgramVersion->Programa, $OldAcademicNodeSubject);
                $newAcademicOffer = $this->findOrCreateAcademicOffer($newCall, $ProgramVersion->Programa, $NewAcademicNodeSubject);

                $this->updateDistributionOffer($newAcademicOffer, $oldAcademicOffer->distribuido);
                $this->replicateResponsabilityOffer($oldAcademicOffer, $newAcademicOffer);
                $this->insertIfNotExistsAcademicOfferAcademicSquare($newAcademicOffer->id, $NewAcademicSquare->id);

                $groupsInfo = $this->proccessAcademicGroups($oldAcademicGroups, $NewAcademicSquare, $newAcademicOffer, true);
                $oldAcademicGroups = $groupsInfo['oldAcademicGroups'];
                $newAcademicGroups = $groupsInfo['newAcademicGroups'];;
                $matchedGroups = $groupsInfo['matchedGroups'];;

                $this->proccessOtherRestrictionsOfOffer($newAcademicOffer, array_map(function($group){ return $group->id; }, $newAcademicGroups));
                $this->changeGroupsOfSelections($NewAcademicSquare, $matchedGroups);

                $this->updateTotalsOfGroupsRestrictionsOffers($oldAcademicGroups, $oldAcademicOffer);
                $this->deleteOldOffer($oldAcademicOffer);
                $this->deleteDelegatesGroups($oldAcademicGroups);

                $this->updateTotalsOfGroupsRestrictionsOffers($newAcademicGroups, $newAcademicOffer);

            };
        }
    }

    /**
     * @param $call
     * @param $term
     * @return mixed
     */
    public function getExtraordinaryCall($call, $term) {
        if (!$term) {
            $term = PeriodosLectivos::getPeriodoLectivoDisponibleDefaultByClass('Matricula');
        }

        return Convocatoria::buscar([
            'nombre' => $call,
            'periodo_lectivo_id' => $term->id,
            'ciclo' => '_EXTRA_ORDINARIO'
        ],[])->first();
    }

    /**
     * @param $subjectVersionId
     * @return mixed
     */
    public function getAcademicNodeByAsignaturaVersion($subjectVersionId)
    {
        return NodoAcademico::buscar([
            'class' => 'AsignaturaVersion',
            'class_id' => $subjectVersionId
        ], [])->first();
    }

    /**
     * @param $programAbrv
     * @param $data
     * @param $term
     * @return void
     * @throws \Exception
     */
    public function proccessUpdatesProgramsAndSubjectsExtraordinaria($programAbrv, $data, $term)
    {

        $currentCall = $this->getExtraordinaryCall($data['current_call'], $term);
        $newCall = $this->getExtraordinaryCall($data['new_call'], $term);

        $oldAsignaturaVersion = $this->getExtraordinarySubjectVersionByAbbreviature($data['subject_abbr']);
        $newAsignaturaVersion = $this->getExtraordinarySubjectVersionByAbbreviature($data['new_subject_abbr']);

        $OldAcademicNodeSubject = $this->getAcademicNodeByAsignaturaVersion($oldAsignaturaVersion->id);
        $NewAcademicNodeSubject = $this->getAcademicNodeByAsignaturaVersion($newAsignaturaVersion->id);

        $OldAcademicSquare = PlazaAcademica::findOrCreate($currentCall, $OldAcademicNodeSubject);
        $NewAcademicSquare = PlazaAcademica::findOrCreate($newCall, $NewAcademicNodeSubject);

        if ($OldAcademicSquare->id === $NewAcademicSquare->id) {
            static::logProccess('Nothing to do. Both squares are the same');
            return;
        }

        $Enrollments = $this->getEnrollmentsByProgram($programAbrv, $data['subject_abbr'], $term);
        $this->updateAcademicSelection($Enrollments, $OldAcademicNodeSubject, $currentCall, $newCall, $NewAcademicSquare);
    }

    /**
     * Process update academic records by academic nodes.
     *
     * @param array $data
     *
     * @return void
     *
     * @throws \Exception
     */
    public function processUpdateAcademicRecordsAnGroupsByAcademicNodes($data)
    {
        $updateAcademicGroupAndRelated = $data['updateGroupsAndOffers'] && filter_var($data['updateGroupsAndOffers'], FILTER_VALIDATE_BOOLEAN);

        foreach ($data['program_abbr'] as $programAbbreviation)
        {
            $programVersion = $this->getVersionProgram($programAbbreviation, $data['program_version']);
            $term = $data['term'] ? $this->getTermByName($data['term']) : null;
            $currentCall = TransferNotesAction::getCallByName($data['current_call'], $term);
            $newCall = TransferNotesAction::getCallByName($data['new_call'], $term);

            $OldAcademicNodeSubject = $currentCall->ciclo !== Convocatoria::CYCLE_EXTRAORDINARY
                ? $this->getAcademicNodeAsignaturaVersionByAbreviature($data['subject_abbr'], $programVersion)
                : $this->getExtraordinaryAcademicNodeBySubject($data['subject_abbr']);

            $NewAcademicNodeSubject = $newCall->ciclo !== Convocatoria::CYCLE_EXTRAORDINARY
                ? ($this->getNewAcademicNodeFromUpdate($data, $programVersion) ?: $OldAcademicNodeSubject)
                : $this->getExtraordinaryAcademicNodeBySubject($data['new_subject_abbr']);

            $oldAcademicSquare = PlazaAcademica::findOrCreate($currentCall, $OldAcademicNodeSubject);
            $newAcademicSquare = PlazaAcademica::findOrCreate($newCall, $NewAcademicNodeSubject);

            if($updateAcademicGroupAndRelated)
            {
                if ($oldAcademicSquare->id === $newAcademicSquare->id)
                    static::logProccess('Nothing to do. Both squares are the same');
                else
                {
                    $this->updateAcademicGroupsAndOffer(
                        $newAcademicSquare,
                        $programVersion,
                        $OldAcademicNodeSubject,
                        $NewAcademicNodeSubject,
                        $currentCall,
                        $newCall
                    );
                }
            }

            $action = new TransferNotesAction($programVersion, $OldAcademicNodeSubject, $NewAcademicNodeSubject, $newCall);
            $action(
                function (RecordAcademico $record){
                    static::logProccess("Error on update academic record [{$record->id}]", false);
                },
                function (RecordAcademico $record){
                    static::logProccess("Error on save academic record [{$record->id}]", false);
                }
            )->each(function (RecordAcademico  $record) use ($NewAcademicNodeSubject) {
                static::logProccess("Academic record [{$record->id}] was updated to read "
                    ."to the subject [{$NewAcademicNodeSubject->abreviatura}].");

                $academicGroup = $record->SeleccionAcademica->GruposAcademicos->first()->id;
                if ($record->NodoEducativo->hasChildNodes())
                    return;

                $record->grupo_academico_id = $academicGroup;
                if ($record->save())
                    static::logProccess("The academic record [{$record->id}] was updated with the academic group [{$academicGroup}]");
            });

            $this->updateAcademicOfferDistributeValue($newAcademicSquare, $programVersion);
        }
    }

    /**
     * Update groups and related academic offers
     *
     * @param PlazaAcademica $academicPlace
     * @param ProgramaVersion $programVersion
     * @param NodoAcademico $oldAcademicNodeSubject
     * @param NodoAcademico $newAcademicNodeSubject
     * @param Convocatoria $currentCall
     * @param Convocatoria $newCall
     *
     * @return void
     *
     * @throws Exception
     */
    public function updateAcademicGroupsAndOffer(
        PlazaAcademica $academicPlace,
        ProgramaVersion $programVersion,
        NodoAcademico $oldAcademicNodeSubject,
        NodoAcademico $newAcademicNodeSubject,
        Convocatoria  $currentCall,
        Convocatoria  $newCall
    )
    {
        $oldAcademicGroups = self::searchAcademicGroupsByAcademicSquare($academicPlace);
        $oldAcademicOffer = $this->findOrCreateAcademicOffer($currentCall, $programVersion->Programa, $oldAcademicNodeSubject);
        $newAcademicOffer = $this->findOrCreateAcademicOffer($newCall, $programVersion->Programa, $newAcademicNodeSubject);

        $this->updateDistributionOffer($newAcademicOffer, $oldAcademicOffer->distribuido);
        $this->replicateResponsabilityOffer($oldAcademicOffer, $newAcademicOffer);
        $this->insertIfNotExistsAcademicOfferAcademicSquare($newAcademicOffer->id, $academicPlace->id);

        $groupsInfo = $this->proccessAcademicGroups($oldAcademicGroups, $academicPlace, $newAcademicOffer, true);
        $oldAcademicGroups = $groupsInfo['oldAcademicGroups'];
        $newAcademicGroups = $groupsInfo['newAcademicGroups'];
        $matchedGroups = $groupsInfo['matchedGroups'];

        $this->proccessOtherRestrictionsOfOffer($newAcademicOffer, array_map(function($group){ return $group->id; }, $newAcademicGroups));
        $this->changeGroupsOfSelections($academicPlace, $matchedGroups);

        $this->updateTotalsOfGroupsRestrictionsOffers($oldAcademicGroups, $oldAcademicOffer);
        $this->deleteOldOffer($oldAcademicOffer);
        $this->deleteDelegatesGroups($oldAcademicGroups);

        $this->updateTotalsOfGroupsRestrictionsOffers($newAcademicGroups, $newAcademicOffer);
    }

    /**
     * Update 'distribuido' field in 'oferta_academica' table
     *
     * @param PlazaAcademica $academicPlace
     * @param ProgramaVersion $programVersion
     *
     * @return void
     */
    private function updateAcademicOfferDistributeValue(PlazaAcademica $academicPlace, ProgramaVersion $programVersion)
    {
        $subjectVersionId = $academicPlace->NodoEducativo->ElementoAcademico->id;

        /** @var OfertaAcademica $offer */
        $offer = OfertaAcademica::buscar([
            'programa_id' => $programVersion->programa_id,
            'convocatoria_id' => $academicPlace->convocatoria_id,
            'class' => 'AsignaturaVersion',
            'class_id' => $subjectVersionId
        ])->first();

        if ($offer->update([ 'distribuido' => true ]))
            static::logProccess("Update 'distribuido' field on academic offer [{$offer->id}]");
    }

    /**
     * Verifica que el traslado de grupos y notas se realizó correctamente
     * @param $data
     * @return void
     */
    public function verifyGroupsUpdate($data)
    {
        $ProgramVersion = $this->getVersionProgram($data['program_abbr'], $data['program_version']);
        $OldAcademicNodeSubject = $this->getAcademicNodeAsignaturaVersionByAbreviature($data['subject_abbr'], $ProgramVersion);
        $NewAcademicNodeSubject = $this->getNewAcademicNodeFromUpdate($data, $ProgramVersion) ?: $OldAcademicNodeSubject;

        $term = $data['term'] ? $this->getTermByName($data['term']) : null;
        $currentCall = $this->getCallByName($data['current_call'], $term);
        $newCall = $this->getCallByName($data['new_call'], $term);

        $OldAcademicSquare = PlazaAcademica::findOrCreate($currentCall, $OldAcademicNodeSubject);
        $NewAcademicSquare = PlazaAcademica::findOrCreate($newCall, $NewAcademicNodeSubject);

        // Obtener inscripciones antes y después
        $oldEnrollments = $this->getEnrollmentsByProgram($data['program_abbr'], $data['subject_abbr'], $term);
        $newEnrollments = $this->getEnrollmentsByProgram($data['program_abbr'], $data['subject_abbr'], $term); // Puede cambiar si cambia la asignatura

        // Verificar que las selecciones académicas de los estudiantes estén en el nuevo grupo/asignatura
        $errores = [];
        foreach ($newEnrollments as $enrollment) {
            $admission = \CTDesarrollo\Core\Expedientes\Registros\Models\Admision::getAdmisionPorConvocatoria($enrollment, $newCall);
            $selection = \CTDesarrollo\Core\Expedientes\Registros\Models\SeleccionAcademica::getSeleccionAcademicaPorAdmision($admission, $NewAcademicNodeSubject->id);
            if (!$selection || !$selection->id) {
                $errores[] = "El estudiante con inscripción {$enrollment->inscripcion_id} no tiene selección académica en el nuevo grupo/asignatura.";
            }
        }
        if (count($errores) === 0) {
            static::logProccess('Verificación exitosa: Todos los estudiantes tienen selección académica en el nuevo grupo/asignatura.', true);
        } else {
            foreach ($errores as $err) {
                static::logProccess($err, false);
            }
        }
    }

}
