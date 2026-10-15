import { joinWithAnd } from '@/lib/format-list';
import { formatInviteWhen } from '@/lib/invite/format';
import styles from './PlanSummary.module.css';

export interface PlanSummaryProps {
  title: string;
  startInstant: string;
  timeZone: string;
  locationName: string;
  attendeeNames: string[];
}

/** The plan's title, zoned date/time, location and who else is coming. */
export function PlanSummary({
  title,
  startInstant,
  timeZone,
  locationName,
  attendeeNames,
}: PlanSummaryProps) {
  const when = formatInviteWhen(startInstant, timeZone);

  return (
    <div className={styles.summary}>
      <h1 className={styles.title}>{title}</h1>
      <div className={styles.when}>
        <span data-testid="plan-when">
          {when.weekday} {when.monthDay}, {when.time}
        </span>
        <span className={styles.where} data-testid="plan-where">
          {locationName} · with {joinWithAnd(attendeeNames)}
        </span>
      </div>
    </div>
  );
}
