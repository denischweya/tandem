import styles from './Avatar.module.css';

export interface AvatarProps {
  /** Shown letter — only the first character is rendered. */
  initial: string;
  /** Diameter in pixels. Matches the design system's `.av` default of 36. */
  size?: number;
  tone?: 'accent2' | 'inverse';
}

/**
 * A small initial-letter circle. Purely decorative next to the name it
 * stands in for, so it is hidden from assistive tech rather than announced
 * twice (the adjacent text already names the person).
 */
export function Avatar({ initial, size = 36, tone = 'accent2' }: AvatarProps) {
  return (
    <span
      aria-hidden="true"
      className={`${styles.avatar} ${tone === 'inverse' ? styles.inverse : styles.accent2}`}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.36) }}
    >
      {initial.charAt(0).toUpperCase()}
    </span>
  );
}
