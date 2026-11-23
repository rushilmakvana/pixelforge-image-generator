import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    is_admin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface Balance {
    formatted: string;
    nanos: number;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    balance: Balance | null;
    flash: { success: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Usage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
}

export interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    status_note: string | null;
    error_code: string | null;
    error_message: string | null;
    estimated: string;
    estimated_min: string;
    estimated_max: string;
    charged: string | null;
    charged_exact: string | null;
    usage: Usage | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    created_at: string;
    completed_at: string | null;
}

export const isActive = (g: Generation) => !['succeeded', 'failed'].includes(g.status);

export type BreadcrumbItemType = BreadcrumbItem;
