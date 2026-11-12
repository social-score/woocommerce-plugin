import type { SessionClassName } from './pixel.js';
export type ConfidenceLevel = 'strong' | 'medium' | 'weak';
export type FieldSource = 'request' | 'collector' | 'vendor' | 'derived' | 'own_data' | 'unknown';
export interface ProfileField<T = unknown> {
    value?: T;
    source: FieldSource;
    sourceDetail?: string;
    confidence?: number;
    confidenceLevel?: ConfidenceLevel;
    reason: string;
    collectedAt?: string;
    masked: boolean;
    /** Score fields only: 70/40 on the value itself. */
    valueLevel?: ConfidenceLevel;
}
export type CategoryStatus = 'available' | 'empty' | 'not_available' | 'disabled';
export interface ProfileCategory<F> {
    status: CategoryStatus;
    fields: Partial<F>;
}
export interface HistoryEntry {
    companyName?: string;
    schoolName?: string;
    title?: string;
    degreeName?: string;
    fieldOfStudy?: string;
    startEndDate?: string | {
        start?: {
            year?: number;
        };
        end?: {
            year?: number;
        };
    };
    companyLocation?: string;
    schoolLocation?: string;
    linkedInUrl?: string;
}
export interface PlatformEntry {
    platform: string;
    checkType: 'email' | 'phone';
    categories?: string[];
    registered: ProfileField<boolean>;
    sources?: {
        sourceDetail: string;
        registered?: boolean;
    }[];
}
export interface IdentityFields {
    names: ProfileField<string>[];
    photos: ProfileField<string>[];
    locations: ProfileField<string>[];
    profileUrls: ProfileField<string>[];
}
export interface ContactFields {
    emailDomain: ProfileField<string>;
    emailDeliverable: ProfileField<boolean>;
    phoneValid: ProfileField<boolean>;
    phoneCountry: ProfileField<string>;
    phoneCarrier: ProfileField<string>;
}
export interface DigitalFootprintFields {
    platforms: PlatformEntry[];
    registeredCount: ProfileField<number>;
    socialMediaAccountsCount: ProfileField<number>;
    chatAccountsCount: ProfileField<number>;
    breachesCount: ProfileField<number>;
}
export interface InterestsFields {
    categories: {
        category: string;
        score: ProfileField<number>;
        differenceToAverage?: number;
    }[];
    studiedLanguages: ProfileField<{
        title?: string;
        xp?: number;
    }>[];
}
export interface ProfessionalFields {
    jobTitle: ProfileField<string>;
    employer: ProfileField<string>;
    summary: ProfileField<string>;
    employmentHistory: ProfileField<HistoryEntry>[];
    educationHistory: ProfileField<HistoryEntry>[];
    skills: ProfileField<string>[];
    connections: ProfileField<number>;
}
export interface B2bFields {
    isBusinessEmail: ProfileField<boolean>;
    employer: ProfileField<string>;
    companyDomain: ProfileField<string>;
}
export interface HealthSignalsFields {
    socialScore: ProfileField<number>;
    emailScore: ProfileField<number>;
    phoneScore: ProfileField<number>;
    fraudScore: ProfileField<number>;
    emailDisposable: ProfileField<boolean>;
    phoneDisposable: ProfileField<boolean>;
    eSignature: ProfileField<boolean>;
}
export interface SegmentsFields {
    similarity: {
        segmentId?: number;
        segmentLabel?: string;
        score: ProfileField<number>;
        /** Strong from 70, medium from 40: the server's word. Absent on a score from before segments. */
        matchLevel?: ConfidenceLevel;
        /** Absent on a score from before segments. */
        segmentStatus?: 'building' | 'ready' | 'failed';
    }[];
}
export interface OnSiteBehaviorFields {
    lastClass: ProfileField<SessionClassName>;
    lastSignals?: ProfileField<string[]>;
    lastAgentSystem?: ProfileField<string>;
    sessions30d: ProfileField<number>;
    lastSeenAt: ProfileField<string>;
    deviceFarm: ProfileField<boolean>;
}
export interface ProfileCategories {
    identity?: ProfileCategory<IdentityFields>;
    contact?: ProfileCategory<ContactFields>;
    digitalFootprint?: ProfileCategory<DigitalFootprintFields>;
    interests?: ProfileCategory<InterestsFields>;
    professional?: ProfileCategory<ProfessionalFields>;
    b2b?: ProfileCategory<B2bFields>;
    healthSignals?: ProfileCategory<HealthSignalsFields>;
    segments?: ProfileCategory<SegmentsFields>;
    ownData?: ProfileCategory<Record<string, never>>;
    /** Present only where the Pixel is on. */
    onSiteBehavior?: ProfileCategory<OnSiteBehaviorFields>;
}
export interface ProfileSubject {
    profileId?: number;
    transactionId?: number;
    email?: ProfileField<string>;
    phone?: ProfileField<string>;
    country: string;
    builtAt: string;
    storage: 'stored' | 'not_stored';
    provenance: 'captured' | 'derived_legacy';
}
export interface Profile {
    schemaVersion: string;
    subject: ProfileSubject;
    categories: ProfileCategories;
    coverage?: {
        collectors?: {
            collectedAt?: string;
        };
        vendor?: {
            collectedAt?: string;
        };
        disabledCategories?: string[];
    };
}
