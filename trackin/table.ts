"use client";

import {
  CustomHeadTable,
  TableHeadCellInterface,
} from "@/components/table/CustomHeadTable";
import { PaginationWithEvents } from "@/components/table/Pagination";
import {
  DeleteFollowUpButton,
  FollowUpEditionButton,
} from "@/components/thesis/follow-up/FollowUpActions";
import { FollowUpRow } from "@/lib/follow-ups";
import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableRow,
  Typography,
} from "@mui/material";
import { useTranslations } from "next-intl";
import React, { useState } from "react";

const headCells: TableHeadCellInterface[] = [
  {
    id: "date",
    label: "ThesisPage.FollowUp.TableHeader.date",
    align: "left",
    width: "144px",
  },
  {
    id: "status",
    label: "ThesisPage.FollowUp.TableHeader.status",
    align: "left",
    width: "144px",
  },
  {
    id: "message",
    label: "ThesisPage.FollowUp.TableHeader.message",
    align: "left",
  },
  {
    id: "attachments",
    label: "ThesisPage.FollowUp.TableHeader.attachments",
    align: "left",
    width: "144px",
  },
  {
    id: "user",
    label: "ThesisPage.FollowUp.TableHeader.user",
    align: "left",
    width: "160px",
  },
  {
    id: "actions",
    label: "ThesisPage.FollowUp.TableHeader.actions",
    align: "right",
    width: "144px",
  },
];

interface Props {
  rows: FollowUpRow[];
  totalRows: number;
  currentPage: number;
  itemsPerPage: number;
  onPageChange: (p: number) => void;
  onRowsPerPageChange: (n: number) => void;
}

export function FollowUpTable({
  rows,
  totalRows,
  currentPage,
  itemsPerPage,
  onPageChange,
  onRowsPerPageChange,
}: Readonly<Props>) {
  const handlePageChange = (
    _event: React.MouseEvent<HTMLButtonElement> | null,
    newPage: number,
  ) => {
    onPageChange(newPage);
  };

  const handleRowsPerPageChange = (
    event: React.ChangeEvent<HTMLInputElement>,
  ) => {
    const newRows = parseInt(event.target.value, 10);
    onRowsPerPageChange(newRows);
    onPageChange(0);
  };

  return (
    <Paper
      elevation={0}
      sx={{
        width: "100%",
        overflow: "hidden",
        borderRadius: "4px",
        border: "1px solid",
        borderColor: "neutral.200",
      }}
    >
      <TableContainer>
        <Table
          sx={{ minWidth: 650, tableLayout: "fixed" }}
          aria-label="Follow-up table"
        >
          <CustomHeadTable headCells={headCells} />
          <FollowUpsTableBody followUps={rows} />
        </Table>
      </TableContainer>

      <PaginationWithEvents
        totalRows={totalRows}
        currentPage={currentPage}
        itemsPerPage={itemsPerPage}
        onPageChange={handlePageChange}
        onRowsPerPageChange={handleRowsPerPageChange}
      />
    </Paper>
  );
}

export function FollowUpsTableBody({
  followUps,
}: Readonly<{
  followUps: {
    id: string;
    date: string;
    status: string;
    message: string;
    attachments: Array<{
      uuid: string;
      name: string;
    }> | null;
    user: {
      user: string;
      email: string;
    };
    editable: boolean;
  }[];
}>) {
  const t = useTranslations("ThesisPage.FollowUp");

  const [expandedMessageRows, setExpandedMessageRows] = useState<string[]>([]);

  const toggleMessageExpand = (followUpId: string) => {
    setExpandedMessageRows((prev: string[]) =>
      prev.includes(followUpId)
        ? prev.filter((id: string) => id !== followUpId)
        : [...prev, followUpId],
    );
  };

  return (
    <TableBody>
      {followUps.length === 0 ? (
        <TableRow>
          <TableCell colSpan={6} align="center" sx={{ py: 14.939 }}>
            <Typography variant="p_small_regular" color="neutral.600">
              {t("no_follow_ups_found")}
            </Typography>
          </TableCell>
        </TableRow>
      ) : (
        followUps.map((followUp) => (
          <TableRow
            key={followUp.id}
            hover
            sx={{
              height: 40,
            }}
          >
            <TableCell>{followUp.date}</TableCell>
            <TableCell>{followUp.status}</TableCell>
            <TableCell
              data-expanded={expandedMessageRows.includes(followUp.id)}
              sx={
                expandedMessageRows.includes(followUp.id)
                  ? {
                      cursor: "pointer",
                      whiteSpace: "normal",
                    }
                  : {
                      cursor: "pointer",
                      whiteSpace: "nowrap",
                      overflow: "hidden",
                      textOverflow: "ellipsis",
                    }
              }
              onClick={() => toggleMessageExpand(followUp.id)}
            >
              {followUp.message}
            </TableCell>
            <TableCell>
              {followUp.attachments && followUp.attachments.length > 0 ? (
                <Box display="flex" flexDirection="column" gap={0.5}>
                  {followUp.attachments.map((attachment, index) => (
                    <Typography
                      key={attachment.uuid}
                      variant="p_small_regular"
                      color="primary.800"
                      sx={{
                        cursor: "pointer",
                        textDecoration: "underline",
                        "&:hover": { color: "primary.600" },
                      }}
                      onClick={() => {
                        // TODO: Implement file download
                        console.log("Download file:", attachment.uuid);
                      }}
                    >
                      {attachment.name}
                    </Typography>
                  ))}
                </Box>
              ) : (
                <Typography variant="p_small_regular" color="neutral.500">
                  {t("no_attachments")}
                </Typography>
              )}
            </TableCell>
            <TableCell>
              <Box display="flex" flexDirection="column" gap={0.5}>
                <Typography variant="p_small_regular">
                  {followUp.user.user}
                </Typography>
                <Typography variant="p_small_regular" color="neutral.600">
                  {followUp.user.email}
                </Typography>
              </Box>
            </TableCell>
            <TableCell sx={{ p: 1 }}>
              {followUp.editable && (
                <Box display="flex" gap="8px" justifyContent="flex-end">
                  <FollowUpEditionButton />
                  <DeleteFollowUpButton />
                </Box>
              )}
            </TableCell>
          </TableRow>
        ))
      )}
    </TableBody>
  );
}

