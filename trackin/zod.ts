import { array, number, object, string } from "zod";

export const createThesisSchema = object({
  program_reference: string()
    .uuid("invalid_program_uuid")
    .nonempty("required_program_uuid"),
  student_reference: string()
    .uuid("invalid_student_uuid")
    .nonempty("required_student_uuid"),
  responsible_ids: array(number().int("invalid_id").positive("invalid_id")),
  research_focus_ids: array(number().int("invalid_id").positive("invalid_id")),
  title: string().min(1, "required_topic").trim(),
  objective: string().trim().optional(),
  observation: string().trim().optional(),
  research_type: string().trim().optional(),
  language: string().trim().optional(),
  created_by_email: string().email("invalid_email").nonempty("required_email"),
});

export const editThesisResponsiblesSchema = object({
  responsible_ids: array(number().int("invalid_id").positive("invalid_id")),
});

export const editThesisSchema = object({
  title: string().min(1, "required_topic").trim().nullable().optional(),
  objective: string().trim().nullable().optional(),
  observation: string().trim().nullable().optional(),
  research_type: string().trim().nullable().optional(),
  language: string().trim().nullable().optional(),
  research_focus_ids: array(
    number().int("invalid_id").positive("invalid_id"),
  ).optional(),
});

export const createFollowUpSchema = object({
  uuid: string().uuid("invalid_uuid").nonempty("required_uuid"),
  user: string().min(1, "required_user").nonempty("required_user"),
  status: string().min(1, "required_status").max(255).trim(),
  message: string().max(400).optional(),
  aggregate_uuid: string()
    .uuid("invalid_thesis_uuid")
    .nonempty("required_thesis_uuid"),
  attachments: array(
    object({
      uuid: string().uuid("invalid_file_uuid").optional(),
      name: string().min(1, "required_file_name").optional(),
      disk: string().optional(),
      path: string().optional(),
      file: string().optional(),
      metadata: object({}).optional(),
    }),
  ).optional(),
});
