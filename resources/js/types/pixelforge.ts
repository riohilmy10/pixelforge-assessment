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
    per_reference_image: number;
    output_tokens: number;
    reasoning_tokens: { typical: number; max: number };
    provider_cost: {
        typical: string;
        max: string;
        typical_nano: number;
        max_nano: number;
    };
    charged: {
        typical: string;
        max: string;
        typical_nano: number;
        max_nano: number;
        typical_display: string;
        max_display: string;
    };
};

export type BalanceSummary = {
    credit_balance_nano: number;
    reserved_nano: number;
    available_nano: number;
    credit_balance_display: string;
    reserved_display: string;
    available_display: string;
};

export type CreditPreset = {
    amount_nano: number;
    display: string;
};

export type CreditTransaction = {
    id: number;
    type: 'purchase' | 'reserve' | 'release' | 'charge';
    label: string;
    description: string | null;
    amount_nano: number;
    amount: string;
    amount_display: string;
    balance_after_display: string;
    reserved_after_display: string;
    created_at: string | null;
};

export type AdminReportTotals = {
    generations: number;
    charged_nano: number;
    charged: string;
    charged_display: string;
    provider_cost_nano: number;
    provider_cost: string;
    provider_cost_display: string;
    profit_nano: number;
    profit: string;
    profit_display: string;
    provider_reported_cost_nano: number | null;
    provider_reported_cost_display: string | null;
    provider_reported_generations: number | null;
    difference_nano: number | null;
    difference_display: string | null;
    provider_matches: boolean;
};

export type AdminReportDay = {
    date: string;
    generations: number;
    charged_nano: number;
    charged: string;
    charged_display: string;
    provider_cost_nano: number;
    provider_cost: string;
    provider_cost_display: string;
    profit_nano: number;
    profit: string;
    profit_display: string;
    provider_reported_cost_nano: number | null;
    provider_reported_cost_display: string | null;
    provider_reported_generations: number | null;
    difference_nano: number | null;
    difference_display: string | null;
    matches: boolean;
};

export type AdminReportUser = {
    user_id: number;
    name: string;
    email: string;
    generations: number;
    charged_nano: number;
    charged: string;
    charged_display: string;
    provider_cost_nano: number;
    provider_cost: string;
    provider_cost_display: string;
    profit_nano: number;
    profit: string;
    profit_display: string;
};

export type AdminReportProvider = {
    available: boolean;
    error: string | null;
    total: {
        cost_nano: number;
        cost: string;
        cost_display: string;
        generations: number;
    } | null;
};

export type AdminReport = {
    period: {
        start_date: string;
        end_date: string;
        days: number;
        max_days: number;
    };
    totals: AdminReportTotals;
    days: AdminReportDay[];
    users: AdminReportUser[];
    provider: AdminReportProvider;
};

export type GenerationOptions = {
    sizes: string[];
    qualities: string[];
    prompt_max: number;
    reference_images_max: number;
};

export type EstimateResponse = {
    estimate: Estimate;
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
