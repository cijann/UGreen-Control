/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_PLATFORM?: 'fnos' | 'unraid';
  readonly VITE_UNRAID_PHASE?: '1' | '2';
}
