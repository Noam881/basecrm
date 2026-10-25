<script setup lang="ts">
import HistoryList from "../../../../crm-core/resources/js/components/HistoryList.vue";
import type { History } from "../types";
defineProps<{ history: History[] }>();
const events = {
    created: "Record created",
    updated: "Record updated",
    archived: "Record archived",
    "document.added": "Document added",
    "document.archived": "Document archived",
    "checklist.created": "Checklist item added",
    "checklist.updated": "Checklist item updated",
    "case.completed": "Case completed",
    "work.created": "Checklist item created",
    "work.updated": "Checklist item updated",
    "work.archived": "Checklist item archived",
};
const fields = {
    employee_reference_id: "Employee",
    manager_employee_reference_id: "Manager",
    owner_id: "Responsible staff",
    role_name: "Role",
    role_description: "Role description",
    start_on: "Start date",
    employment_status: "Employment status",
    vacancy_id: "Vacancy",
    next_action_on: "Next action date",
    due_on: "Due date",
    final_work_on: "Final working date",
    final_pay_info: "Final-pay information",
    completed_on: "Completion date",
    category: "Category",
    required: "Required work",
    case_id: "HR case",
    kind: "Purpose",
    "module:hr:case_id": "HR case",
    "module:hr:category": "Category",
    "module:hr:required": "Required work",
};
</script>
<template>
    <HistoryList
        :history="history"
        :event-labels="events"
        :field-labels="fields"
    />
</template>
