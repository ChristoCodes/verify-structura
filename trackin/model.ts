"use client";

import { useSnackbar } from "@/context/SnackbarContext";
import { uploadFile } from "@/lib/files";
import { createFollowUp } from "@/lib/follow-ups";
import CloseIcon from "@mui/icons-material/Close";
import UploadFileIcon from "@mui/icons-material/UploadFile";
import {
  Box,
  Button,
  IconButton,
  LinearProgress,
  Modal,
  TextField,
  Typography,
} from "@mui/material";
import { useTranslations } from "next-intl";
import React, { useCallback, useState } from "react";
import { useDropzone } from "react-dropzone";

interface Props {
  open: boolean;
  onClose: () => void;
  thesisUuid: string;
  onFollowUpCreated?: () => void;
}

export function FollowUpModal({ open, onClose, thesisUuid, onFollowUpCreated }: Readonly<Props>) {
  const t = useTranslations("ThesisPage.FollowUp.Modal");
  const { openSnackbar } = useSnackbar();

  const [status, setStatus] = useState("");
  const [message, setMessage] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [fileUuids, setFileUuids] = useState<string[]>([]);
  const [showUploader, setShowUploader] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [loading, setLoading] = useState(false);

  const onDrop = useCallback(
    async (accepted: File[]) => {
      if (!accepted.length) return;
      
      setUploading(true);
      setProgress(0);
      
      const newFiles = [...files];
      const newFileUuids = [...fileUuids];
      
      for (const file of accepted) {
        let fake = 0;
        const id = setInterval(() => {
          fake = Math.min(fake + 10, 99);
          setProgress(fake);
        }, 150);

        const result = await uploadFile(file);
        clearInterval(id);
        setProgress(100);

        if (result && result.uuid) {
          newFiles.push(file);
          newFileUuids.push(result.uuid);
        } else {
          setProgress(0);
          openSnackbar(t("unknown_error"), "error");
        }
      }
      
      setFiles(newFiles);
      setFileUuids(newFileUuids);
      setUploading(false);
    },
    [files, fileUuids, openSnackbar, t],
  );

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: {
      "application/pdf": [".pdf"],
      "application/msword": [".doc"],
      "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
        [".docx"],
      "application/vnd.oasis.opendocument.text": [".odt"],
    },
    maxFiles: 5,
    maxSize: 15 * 1024 * 1024,
  });

  const resetForm = () => {
    setStatus("");
    setMessage("");
    setFiles([]);
    setFileUuids([]);
    setShowUploader(false);
    setUploading(false);
    setProgress(0);
  };

  const handleClose = () => {
    onClose();
    resetForm();
    setLoading(false);
  };

  const handleSave = async () => {
    if (!status.trim()) {
      openSnackbar(t("required"), "error");
      return;
    }

    setLoading(true);
    
    const attachments = files.map((file, index) => ({
      uuid: fileUuids[index],
      name: file.name,
    }));

    // Generate UUID for the tracking and get current user info
    // TODO: Get actual user info from authentication context
    const trackingUuid = crypto.randomUUID();
    const currentUser = "Usuario Actual"; // TODO: Get from auth context

    const result = await createFollowUp({
      uuid: trackingUuid,
      user: currentUser,
      aggregate_uuid: thesisUuid,
      status: status.trim(),
      message: message.trim() || null,
      attachments,
    });

    if (result.errors) {
      const firstField = Object.keys(result.errors)[0];
      const firstMsgKey = result.errors[firstField]?.[0] ?? "unknown_error";
      openSnackbar(t(firstMsgKey as string), "error");
      setLoading(false);
    } else if (result.status && result.status >= 200 && result.status < 300) {
      openSnackbar(t("changes_saved_successfully"), "success");
      // Llamar al callback para actualizar la lista
      if (onFollowUpCreated) {
        onFollowUpCreated();
      }
      handleClose();
    } else if (result.status === 500) {
      openSnackbar(
        t("could_not_connect_to_the_server_please_try_again_later"),
        "error",
      );
      setLoading(false);
    } else {
      openSnackbar(t("unknown_error"), "error");
      setLoading(false);
    }
  };

  const removeFile = (index: number) => {
    const newFiles = files.filter((_: File, i: number) => i !== index);
    const newFileUuids = fileUuids.filter((_: string, i: number) => i !== index);
    setFiles(newFiles);
    setFileUuids(newFileUuids);
  };

  return (
    <Modal open={open} disableAutoFocus>
      <Box
        width={{ xs: "95%", sm: "80%", md: "640px" }}
        top="50%"
        left="50%"
        bgcolor="white"
        borderRadius={2}
        position="absolute"
        sx={{ transform: "translate(-50%, -50%)" }}
      >
        <Box px={3} py={2}>
          <Typography component="p" variant="t_medium_semibold">
            {t("title")}
          </Typography>
        </Box>
        <Box px={3} py={1} display="flex" flexDirection="column" gap={2}>
          <TextField
            label={t("status")}
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            required
            size="small"
            helperText={`${status.length}/255`}
            slotProps={{ input: { inputProps: { maxLength: 255 } } }}
          />
          <TextField
            label={t("message")}
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            multiline
            rows={4}
            size="small"
            helperText={`${message.length}/400`}
            slotProps={{ input: { inputProps: { maxLength: 400 } } }}
          />
          <Box>
            {!showUploader ? (
              <Button variant="text" onClick={() => setShowUploader(true)}>
                {t("add_document")}
              </Button>
            ) : (
              <>
                {files.length === 0 ? (
                  <Box
                    {...getRootProps()}
                    border="1px dashed"
                    borderColor="neutral.300"
                    borderRadius={2}
                    height={144}
                    display="flex"
                    flexDirection="column"
                    justifyContent="center"
                    alignItems="center"
                    sx={{
                      cursor: "pointer",
                      "&:hover": { borderColor: "primary.800" },
                    }}
                  >
                    <input {...getInputProps()} />
                    <Box
                      display="flex"
                      alignItems="center"
                      flexDirection="column"
                      gap={1}
                    >
                      <Box
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                        bgcolor="primary.50"
                        borderRadius="50%"
                        p={1}
                      >
                        <UploadFileIcon
                          fontSize="large"
                          sx={{ color: "primary.800" }}
                        />
                      </Box>
                      {isDragActive ? (
                        t("drop_here")
                      ) : (
                        <Box display="flex" gap={0.5}>
                          <Typography
                            variant="p_medium_regular"
                            color="primary.800"
                            sx={{
                              textDecoration: "underline",
                              textDecorationColor: "#81D4FA",
                            }}
                          >
                            {t("click_to_upload")}
                          </Typography>
                          <Typography variant="p_medium_regular">
                            {t("or_drag_and_drop")}
                          </Typography>
                        </Box>
                      )}
                      <Typography
                        variant="p_medium_regular"
                        color="neutral.700"
                      >
                        .pdf, .doc, .docx, .odt (max. 15 MB)
                      </Typography>
                    </Box>
                  </Box>
                ) : (
                  <Box display="flex" flexDirection="column" gap={2}>
                                         {files.map((file: File, index: number) => (
                       <Box
                         key={index}
                         border="1px solid"
                         borderColor="neutral.300"
                         p={2}
                         borderRadius={2}
                         display="flex"
                         flexDirection="column"
                         gap={2}
                       >
                         <Box display="flex" gap={2} alignItems="center">
                           <Box width="100%">
                             <Typography
                               variant="p_medium_semibold"
                               component="p"
                               sx={{
                                 overflowWrap: "anywhere",
                               }}
                             >
                               {file.name}
                             </Typography>
                             <Typography
                               variant="p_medium_regular"
                               color="neutral.600"
                               component="p"
                             >
                               {(file.size / 1024).toFixed(1)} kb{" "}
                               {progress === 100
                                 ? t("completed")
                                 : uploading && ` • ${progress}%`}
                             </Typography>
                           </Box>
                           {!uploading && (
                             <IconButton
                               size="large"
                               onClick={() => removeFile(index)}
                             >
                               <CloseIcon />
                             </IconButton>
                           )}
                         </Box>
                         {uploading && (
                           <LinearProgress
                             variant="determinate"
                             value={progress}
                             sx={{ bgcolor: "primary.800" }}
                           />
                         )}
                       </Box>
                     ))}
                    {files.length < 5 && (
                      <Box
                        {...getRootProps()}
                        border="1px dashed"
                        borderColor="neutral.300"
                        borderRadius={2}
                        height={80}
                        display="flex"
                        flexDirection="column"
                        justifyContent="center"
                        alignItems="center"
                        sx={{
                          cursor: "pointer",
                          "&:hover": { borderColor: "primary.800" },
                        }}
                      >
                        <input {...getInputProps()} />
                        <Typography variant="p_medium_regular" color="primary.800">
                          {t("add_more_files")}
                        </Typography>
                      </Box>
                    )}
                  </Box>
                )}
              </>
            )}
          </Box>
        </Box>
        <Box px={3} py={2} display="flex" gap={1} justifyContent="end">
          <Button
            variant="text"
            color="neutral"
            onClick={handleClose}
            disabled={loading}
          >
            {t("cancel")}
          </Button>
          <Button variant="text" onClick={handleSave} disabled={loading}>
            {loading ? t("saving") : t("save")}
          </Button>
        </Box>
      </Box>
    </Modal>
  );
}

