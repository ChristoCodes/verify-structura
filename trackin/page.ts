import { FollowUpClient } from "@/components/thesis/follow-up/FollowUpClient";
import { getStudentByUuidAndThesisUuid } from "@/lib/students";
import { getThesisByUuid } from "@/lib/thesis";
import { getTranslations } from "next-intl/server";

type Params = Promise<{
  uuid: string;
  locale: string;
}>;

export async function generateMetadata(props: Readonly<{ params: Params }>) {
  const params = await props.params;
  const thesis = await getThesisByUuid(params.uuid);
  const t = await getTranslations("ThesisPage.FollowUp");
  const student = await getStudentByUuidAndThesisUuid(
    thesis!.student_reference,
    thesis!.uuid,
  );

  return {
    title: `${t("follow_ups")} - ${student!.firstname} ${student!.lastname} - ${student!.program.abbr}`,
  };
}

export default async function FollowUpPage(
  props: Readonly<{ params: Params }>,
) {
  const params = await props.params;
  const thesis = await getThesisByUuid(params.uuid);
  
  return (
    <>
      {/* 
        El componente FollowUpClient ahora:
        - Carga automáticamente los datos del backend
        - Maneja estados de loading y error
        - Permite crear nuevos follow-ups
        - Se actualiza en tiempo real
      */}
      <FollowUpClient thesisUuid={thesis!.uuid} />
    </>
  );
}

