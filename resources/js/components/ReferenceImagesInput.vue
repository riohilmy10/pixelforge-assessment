<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Repeater for optional reference image URLs.
 *
 * The cap comes from the backend (`options.reference_images_max`), so the UI guard and
 * the server validation cannot drift. The parent owns the array (through v-model) so the
 * same values are used for the live quote and the submit payload.
 */
const props = defineProps<{
    modelValue: string[];
    error?: string;
    max?: number;
}>();

const emit = defineEmits<{
    (event: 'update:modelValue', value: string[]): void;
}>();

const maxImages = computed(() => props.max ?? 4);

/** Keep at least one row in the editor even when the list is cleared. */
const rows = computed(() =>
    props.modelValue.length > 0 ? props.modelValue : [''],
);

function update(index: number, value: string | number): void {
    const next = [...props.modelValue];
    next[index] = String(value);
    emit('update:modelValue', next);
}

function add(): void {
    if (props.modelValue.length < maxImages.value) {
        emit('update:modelValue', [...props.modelValue, '']);
    }
}

function remove(index: number): void {
    const next = props.modelValue.filter((_, position) => position !== index);
    emit('update:modelValue', next.length > 0 ? next : ['']);
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <Label>Reference images</Label>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="modelValue.length >= maxImages"
                @click="add"
            >
                Add image
            </Button>
        </div>
        <p class="text-xs text-muted-foreground">
            Optional http(s) URLs. Each one adds extra input image tokens and is
            included in the estimate above.
        </p>

        <div
            v-for="(url, index) in rows"
            :key="index"
            class="flex items-center gap-2"
        >
            <Input
                :model-value="url"
                type="url"
                placeholder="Paste an image URL"
                @update:model-value="update(index, $event)"
            />
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="remove(index)"
            >
                Remove
            </Button>
        </div>

        <InputError :message="error" />
    </div>
</template>
