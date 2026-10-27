export const CAREVMA_LOGO_URL = '/Care_VMA.webp';
export const CAREVMA_FAVICON_URL = '/Care_VMA-favicon.png';
export const CAREVMA_REMOTE_LOGO_URL = 'https://carevmahealth.com/wp-content/uploads/2025/11/7a9706c5-1404-40b4-a67e-cfd4ce46af8a-2048x382-1.webp';

export default function BrandLogo({ compact = false, className = '' }) {
  return (
    <span className={`carevma-brand-logo ${compact ? 'is-compact' : ''} ${className}`.trim()}>
      <img
        src={compact ? CAREVMA_FAVICON_URL : CAREVMA_LOGO_URL}
        alt="CareVMA"
        onError={event => {
          event.currentTarget.onerror = null;
          event.currentTarget.src = compact ? CAREVMA_FAVICON_URL : CAREVMA_REMOTE_LOGO_URL;
        }}
      />
    </span>
  );
}
