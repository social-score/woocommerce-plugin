import type { ReactElement } from 'react';
import type { ProfileRef } from '../lib/format.js';
/**
 * What the Pixel saw of the visit behind this customer or order. Loads when it mounts, with no click
 * and no charge. Shows nothing while loading and on any failure: no data (404), the Pixel off in this
 * environment (404) and a key without the scope (403) are normal, and the Check button is the panel's job.
 */
export declare function SessionClass({ kind, externalId }: ProfileRef): ReactElement | null;
