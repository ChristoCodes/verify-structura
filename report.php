<?php

    protected function loadData(&$structure, $filters = [])
    {
        $reportPreData = $this->reporter->generate($filters, ['max' => 1]);
        $reportData = $this->reporter->generate($filters, ['max' => $reportPreData->get('total')]);
        $transformadasData = $reportData->get('items');

        // Ordenar los datos según sortBy y sortDesc si existen
        $sortBy = isset($filters['sortBy']) ? $filters['sortBy'] : null;
        $sortDesc = isset($filters['sortDesc']) ? filter_var($filters['sortDesc'], FILTER_VALIDATE_BOOLEAN) : false;
        if ($sortBy) {
            $transformadasData = collect($transformadasData)->sortBy(function($item) use ($sortBy) {
                // Normalizar para evitar errores si la clave no existe
                return isset($item[$sortBy]) ? $item[$sortBy] : null;
            }, SORT_REGULAR, $sortDesc)->values();
        }

        $startRow = 2;

        foreach ($transformadasData as $transformada) {
            $callback = $this->dataToCells($startRow);
            $cells = $callback($transformada);

            foreach ($cells as $cell) {
                $structure['items'][] = $cell;
            }

            $startRow++;
        }
    }
