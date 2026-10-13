import { type ReactElement } from 'react';
export type ChatRole = 'user' | 'assistant';
export interface ChatMessage {
    id: string;
    role: ChatRole;
    /** Plain text. Rendered as text, never as HTML or Markdown. */
    text: string;
    timestamp?: Date | string | number;
}
export interface ChatBubbleProps {
    role: ChatRole;
    text: string;
    timestamp?: Date | string | number;
    /** Spoken before the text, e.g. "You" / "MarSel". Hidden visually. */
    author?: string;
    locale?: string;
    className?: string;
}
export declare function ChatBubble({ role, text, timestamp, author, locale, className, }: ChatBubbleProps): ReactElement;
export interface TypingIndicatorProps {
    /** Accessible text, announced politely. */
    label?: string;
    className?: string;
}
/** Dots cycle with a timer rather than CSS keyframes, and stay still for reduced-motion users. */
export declare function TypingIndicator({ label, className, }: TypingIndicatorProps): ReactElement;
export interface ChatThreadProps {
    messages: ChatMessage[];
    /** Shows the typing indicator after the last message. */
    typing?: boolean;
    /** Accessible name of the conversation. */
    label?: string;
    userLabel?: string;
    assistantLabel?: string;
    typingLabel?: string;
    locale?: string;
    className?: string;
}
export declare function ChatThread({ messages, typing, label, userLabel, assistantLabel, typingLabel, locale, className, }: ChatThreadProps): ReactElement;
