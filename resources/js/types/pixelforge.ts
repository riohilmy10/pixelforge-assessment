/**
 * Shapes shared by the generator, history and cost preview.
 *
 * Money fields come from the backend as exact decimal strings (nine places) plus a
 * trimmed display string. Never do float math on these in the client; only display them.
 */

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    is_active: boolean;
    is_terminal: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    image_url: string | null;
    error_code: string | null;
    error_message: string | null;
    /** Worst-case quote shown at submit, e.g. "0.000575000". */
    estimated_cost: string;
    estimated_cost_display: string;
    /** Actual recorded cost once succeeded, otherwise null. */
    actual_cost: string | null;
    actual_cost_display: string | null;
    created_at: string | null;
    completed_at: string | null;
};

export type Estimate = {
    text_tokens: { typical: number; max: number };
    image_tokens: number;
    output_tokens: number;
    reasoning_tokens: { typical: number; max: number };
    provider_cost: { typical: string; max: string };
    charged: {
        typical: string;
        max: string;
        typical_display: string;
        max_display: string;
    };
};

export type EstimateResponse = {
    estimate: Estimate;
};

export type GenerationOptions = {
    sizes: string[];
    qualities: string[];
    prompt_max: number;
    reference_images_max: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};
