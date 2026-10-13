import type { ReactElement } from 'react';
export interface ConnectScreenProps {
    /**
     * The adapter's connect URL: `GET /api/v2/stores/connect?platform=&shop=&redirect_uri=&state=`
     * with the adapter's own state and PKCE challenge already filled in.
     */
    connectUrl: string;
}
export declare function ConnectScreen({ connectUrl }: ConnectScreenProps): ReactElement;
