"use client";

import {
  FilterOption,
  TableAutocomplete,
} from "@/components/table/TableAutocomplete";
import { FollowUpModal } from "@/components/thesis/follow-up/FollowUpModal";
import { FollowUpTable } from "@/components/thesis/follow-up/Table";
import { FollowUpRow, searchFollowUps, transformTrackingToFollowUpRow } from "@/lib/follow-ups";
import { Add } from "@mui/icons-material";
import { Box, Button, CircularProgress } from "@mui/material";
import { useTranslations } from "next-intl";
import React, { useCallback, useEffect, useMemo, useReducer, useState } from "react";

type Order = "asc" | "desc" | null;

interface FilterState {
  order: Order;
  user: string | null;
  status: string | null;
}

type FilterAction =
  | { type: "order"; payload: Order }
  | { type: "user"; payload: string | null }
  | { type: "status"; payload: string | null }
  | { type: "reset" };

const initialFilters: FilterState = {
  order: null,
  user: null,
  status: null,
};

function filtersReducer(state: FilterState, action: FilterAction): FilterState {
  switch (action.type) {
    case "order":
      return { ...state, order: action.payload };
    case "user":
      return { ...state, user: action.payload };
    case "status":
      return { ...state, status: action.payload };
    case "reset":
      return initialFilters;
    default:
      return state;
  }
}

function useFollowUpFilters(rows: FollowUpRow[]) {
  const [filters, dispatch] = useReducer(filtersReducer, initialFilters);

  const userOptions = useMemo(
    () =>
      [...new Set(rows.map((r) => r.user.user))].map((u) => ({
        label: u,
        value: u,
      })),
    [rows],
  );

  const statusOptions = useMemo(
    () =>
      [...new Set(rows.map((r) => r.status))].map((s) => ({
        label: s,
        value: s,
      })),
    [rows],
  );

  const filteredRows = useMemo(() => {
    let result = rows;

    if (filters.user) result = result.filter((r) => r.user.user === filters.user);
    if (filters.status)
      result = result.filter((r) => r.status === filters.status);

    if (filters.order) {
      result = [...result].sort((a, b) =>
        filters.order === "asc"
          ? a.date.localeCompare(b.date)
          : b.date.localeCompare(a.date),
      );
    }

    return result;
  }, [rows, filters]);

  return { filters, dispatch, userOptions, statusOptions, filteredRows };
}

interface FilterBarProps {
  filters: FilterState;
  dispatch: React.Dispatch<FilterAction>;
  userOptions: { label: string; value: string }[];
  statusOptions: { label: string; value: string }[];
  onAdd: () => void;
}

function FilterBar({
  filters,
  dispatch,
  userOptions,
  statusOptions,
  onAdd,
}: Readonly<FilterBarProps>) {
  const t = useTranslations("ThesisPage.FollowUp");

  const pickValue = (option: FilterOption | null) =>
    option ? option.value : null;

  return (
    <Box
      display="flex"
      gap={2}
      alignItems="center"
      justifyContent="space-between"
      flexWrap="wrap"
    >
      <Box display="flex" gap={2} alignItems="center" flexWrap="wrap">
        <TableAutocomplete
          placeholder={t("user")}
          width={172}
          size="small"
          noOptionsText={t("no_options_found")}
          options={userOptions}
          multiple={false}
          value={
            filters.user
              ? (userOptions.find((o) => o.value === filters.user) ?? null)
              : null
          }
          onChange={(opt) =>
            dispatch({
              type: "user",
              payload: Array.isArray(opt) ? null : pickValue(opt),
            })
          }
        />

        <TableAutocomplete
          placeholder={t("status")}
          width={172}
          size="small"
          noOptionsText={t("no_options_found")}
          options={statusOptions}
          multiple={false}
          value={
            filters.status
              ? (statusOptions.find((o) => o.value === filters.status) ?? null)
              : null
          }
          onChange={(opt) =>
            dispatch({
              type: "status",
              payload: Array.isArray(opt) ? null : pickValue(opt),
            })
          }
        />

        <Button
          variant="text"
          color="neutral"
          onClick={() => dispatch({ type: "reset" })}
          disabled={
            !(
              filters.order !== null ||
              filters.user !== null ||
              filters.status !== null
            )
          }
        >
          {t("clear")}
        </Button>
      </Box>

      <Button variant="text" startIcon={<Add />} onClick={onAdd}>
        {t("add_follow_up")}
      </Button>
    </Box>
  );
}

interface Props {
  thesisUuid: string;
}

export function FollowUpClient({ thesisUuid }: Readonly<Props>) {
  const [followUps, setFollowUps] = useState<FollowUpRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = React.useState(false);
  const [page, setPage] = React.useState(0);
  const [rowsPerPage, setRowsPerPage] = React.useState(5);

  const { filters, dispatch, userOptions, statusOptions, filteredRows } =
    useFollowUpFilters(followUps);

  // Cargar datos del backend
  const loadFollowUps = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      
      const trackings = await searchFollowUps(thesisUuid);
      const transformedFollowUps = trackings.map(transformTrackingToFollowUpRow);
      setFollowUps(transformedFollowUps);
    } catch (err) {
      console.error('Error loading follow-ups:', err);
      setError('Error al cargar los seguimientos');
    } finally {
      setLoading(false);
    }
  }, [thesisUuid]);

  // Cargar datos al montar el componente
  useEffect(() => {
    loadFollowUps();
  }, [loadFollowUps]);

  // Actualizar datos después de crear un nuevo follow-up
  const handleFollowUpCreated = useCallback(async () => {
    await loadFollowUps();
    setPage(0); // Resetear a la primera página
  }, [loadFollowUps]);

  const pageRows = useMemo(() => {
    const start = page * rowsPerPage;
    return filteredRows.slice(start, start + rowsPerPage);
  }, [filteredRows, page, rowsPerPage]);

  React.useEffect(() => setPage(0), [filters]);

  // Mostrar loading
  if (loading) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" py={4}>
        <CircularProgress />
      </Box>
    );
  }

  // Mostrar error
  if (error) {
    return (
      <Box display="flex" flexDirection="column" alignItems="center" py={4} gap={2}>
        <Box color="error.main">{error}</Box>
        <Button variant="outlined" onClick={loadFollowUps}>
          Reintentar
        </Button>
      </Box>
    );
  }

  return (
    <Box display="flex" flexDirection="column" gap={2}>
      <FilterBar
        filters={filters}
        dispatch={dispatch}
        userOptions={userOptions}
        statusOptions={statusOptions}
        onAdd={() => setOpen(true)}
      />

      <FollowUpTable
        rows={pageRows}
        totalRows={filteredRows.length}
        currentPage={page}
        itemsPerPage={rowsPerPage}
        onPageChange={setPage}
        onRowsPerPageChange={setRowsPerPage}
      />
      
      {open && (
        <FollowUpModal
          open={open}
          onClose={() => setOpen(false)}
          thesisUuid={thesisUuid}
          onFollowUpCreated={handleFollowUpCreated}
        />
      )}
    </Box>
  );
}

