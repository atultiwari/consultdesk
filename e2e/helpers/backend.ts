import { execFileSync } from 'node:child_process';

/**
 * Runs small SQL statements against the API's database through PHP (which is always there).
 * Locally that is the docker container; in CI, PHP on the runner. Override with E2E_PHP,
 * e.g. E2E_PHP="php" or E2E_PHP="docker compose -f ../docker-compose.yml exec -T api php".
 */
const PHP = (process.env.E2E_PHP ?? 'docker compose -f ../docker-compose.yml exec -T api php').split(' ');

const CONNECT =
  '$p = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_NAME")), getenv("DB_USER"), getenv("DB_PASSWORD"));';

export function sql(statement: string, params: (string | number)[] = []): string {
  const code = `${CONNECT} $s = $p->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->columnCount() > 0 ? (string) $s->fetchColumn() : "";`;
  return execFileSync(PHP[0], [...PHP.slice(1), '-r', code, '--', statement, ...params.map(String)], { encoding: 'utf8' }).trim();
}

/** Clears request counters so repeated local runs are not rate limited. */
export function resetRateLimits(): void {
  sql('DELETE FROM rate_limits');
}
