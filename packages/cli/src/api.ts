export const VERSION = '0.3.0';

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }

  describe(): string {
    const lines = [this.message];
    for (const [field, messages] of Object.entries(this.errors)) {
      for (const m of messages) lines.push(`  - ${field}: ${m}`);
    }
    return lines.join('\n');
  }
}

export type Fetch = typeof fetch;

export interface Project {
  id: number;
  name: string;
  description: string | null;
  repository_url: string | null;
  archived: boolean;
}

export interface Feature {
  id: number;
  name: string;
  description: string | null;
  status: string;
  verification_status: string;
  category: { id: number; name: string } | null;
  acceptance_criteria?: { description: string }[];
}

export interface RoadmapPhase {
  key: string;
  name: string;
  description: string | null;
  status: 'planned' | 'in_progress' | 'done' | 'blocked';
  target_date: string | null;
  progress: { items: number; done: number; percent: number | null; features: number; implemented: number; verified: number };
  items: { text: string; done: boolean; feature: { id: number; name: string; status: string; verification_status: string } | null }[];
}

export interface Roadmap {
  synced_at: string | null;
  phases: RoadmapPhase[];
}

export interface TicketComment {
  id: number;
  ticket_id: number;
  author_role: string;
  author_name: string | null;
  body: string | null;
  from_status: string | null;
  to_status: string | null;
  report_id: number | null;
  created_at: string;
}

export interface Ticket {
  id: number;
  reference: string | null;
  title: string;
  description: string | null;
  type: string;
  status: string;
  priority: string | null;
  raised_by: string | null;
  feature: { id: number; name: string } | null;
  comments_count: number;
  latest_feedback: TicketComment | null;
  updated_at: string;
  comments?: TicketComment[];
}

/** A PRD vs design mismatch (GET /mismatches/{id}). */
export interface Mismatch {
  id: number;
  key: string | null;
  reference: string | null;
  title: string;
  feature: { id: number; name: string } | null;
  prd_says: string;
  design_says: string;
  built_to: string;
  built_note: string | null;
  impact: string | null;
  decide_by: string;
  status: string;
  decision: string | null;
  decision_note: string | null;
  next_action: string | null;
}

export interface Dashboard {
  project: Project;
  summary: {
    total: number;
    by_status: Record<string, number>;
    by_verification_status: Record<string, number>;
    implemented_and_verified: number;
    needs_attention: number;
    progress: { implemented_percent: number | null; verified_percent: number | null };
    reports: number;
    open_unresolved: number;
    unresolved_waiting_on?: { client: number; team: number; agent: number; unsorted: number };
    last_report_at: string | null;
    tickets?: { total: number; open: number; sent_back: number; by_status: Record<string, number> };
    mismatches?: { open: number; team: number; client: number; decided: number; applied: number };
  };
  needs_attention: Feature[];
  unresolved: { id: number; text: string; waiting_on?: 'client' | 'team' | 'agent' | null; decision_note?: string | null; report_id: number | null; reported_at: string }[];
  recent_reports: { id: number; summary: string; reported_at: string }[];
  open_tickets?: Ticket[];
  mismatches?: Mismatch[];
  recent_feedback?: (TicketComment & { ticket: { id: number; reference: string | null; title: string; status: string } })[];
}

export interface Report {
  id: number;
  features: { id: number; name: string; claimed_status: string | null }[];
  tickets?: { id: number; label: string; title: string; to_status: string | null }[];
}

export interface TestRunSummary {
  id: number;
  totals: { total: number; passed: number; failed: number; skipped: number };
  features: Record<string, number>;
  feature_outcomes: { feature_id: number; outcome: string; reason: string | null }[];
}

export interface ScanSummary {
  id: number;
  candidates: number;
  new: number;
  updated: number;
  pending_review: number;
}

/** Abilities requested for the coding-agent token: read, record tracking data, reports and scans. Never verify or manage projects, members or tokens. */
export const CLI_ABILITIES = ['read', 'report', 'record'];

/** Tokens created by "unseen init" expire after this many days; run init --force to renew. */
export const CLI_TOKEN_DAYS = 90;

export class ApiClient {
  private readonly baseUrl: string;
  private readonly token: string | null;
  private readonly fetchImpl: Fetch;

  constructor(baseUrl: string, token: string | null, fetchImpl: Fetch = fetch) {
    this.baseUrl = baseUrl;
    this.token = token;
    this.fetchImpl = fetchImpl;
  }

  async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'User-Agent': `unseen-cli/${VERSION}`,
    };
    if (this.token) headers.Authorization = `Bearer ${this.token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    let response: Response;
    try {
      response = await this.fetchImpl(`${this.baseUrl}/api/v1${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        redirect: 'error',
        signal: AbortSignal.timeout(30_000),
      });
    } catch (error) {
      throw new ApiError(0, `Could not reach Unseen at ${this.baseUrl}: ${(error as Error).message}`);
    }

    const text = await response.text();
    let data: any = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      // Non-JSON body (e.g. proxy error page); fall through with status only.
    }

    if (!response.ok) {
      const message = response.status === 401
        ? 'Not authenticated. Run "unseen init" or set UNSEEN_TOKEN.'
        : response.status === 403
          ? 'Not allowed. The token or user does not have access to this.'
          : (data?.message ?? `Request failed with HTTP ${response.status}.`);
      throw new ApiError(response.status, message, data?.errors ?? {});
    }
    return data as T;
  }

  async createToken(email: string, password: string, tokenName: string): Promise<string> {
    const data = await this.request<{ token: string }>('POST', '/auth/tokens', {
      email, password, token_name: tokenName, abilities: CLI_ABILITIES, expires_in_days: CLI_TOKEN_DAYS,
    });
    return data.token;
  }

  async me(): Promise<{ id: number; name: string; email: string }> {
    return (await this.request<{ data: { id: number; name: string; email: string } }>('GET', '/me')).data;
  }

  async listProjects(): Promise<Project[]> {
    return (await this.request<{ data: Project[] }>('GET', '/projects?per_page=100')).data;
  }

  async project(id: number): Promise<Project> {
    return (await this.request<{ data: Project }>('GET', `/projects/${id}`)).data;
  }

  async dashboard(id: number): Promise<Dashboard> {
    return (await this.request<{ data: Dashboard }>('GET', `/projects/${id}/dashboard`)).data;
  }

  /** All features of a project (follows pagination, capped at 50 pages of 100). */
  async features(id: number): Promise<Feature[]> {
    const all: Feature[] = [];
    for (let page = 1; page <= 50; page++) {
      const res = await this.request<{ data: Feature[]; meta: { last_page: number } }>(
        'GET', `/projects/${id}/features?per_page=100&page=${page}`,
      );
      all.push(...res.data);
      if (page >= res.meta.last_page) break;
    }
    return all;
  }

  async submitScan(id: number, payload: unknown): Promise<ScanSummary> {
    return (await this.request<{ data: ScanSummary }>('POST', `/projects/${id}/scans`, payload)).data;
  }

  async submitTestRun(id: number, payload: unknown): Promise<TestRunSummary> {
    return (await this.request<{ data: TestRunSummary }>('POST', `/projects/${id}/test-runs`, payload)).data;
  }

  async roadmap(id: number): Promise<Roadmap> {
    return (await this.request<{ data: Roadmap }>('GET', `/projects/${id}/roadmap`)).data;
  }

  async syncRoadmap(id: number, payload: unknown): Promise<Roadmap> {
    return (await this.request<{ data: Roadmap }>('PUT', `/projects/${id}/roadmap`, payload)).data;
  }

  /** Tickets of a project (follows pagination, capped at 20 pages of 100). `query` is e.g. "state=open". */
  async tickets(id: number, query = ''): Promise<Ticket[]> {
    const all: Ticket[] = [];
    for (let page = 1; page <= 20; page++) {
      const res = await this.request<{ data: Ticket[]; meta: { last_page: number } }>(
        'GET', `/projects/${id}/tickets?per_page=100&page=${page}${query ? `&${query}` : ''}`,
      );
      all.push(...res.data);
      if (page >= res.meta.last_page) break;
    }
    return all;
  }

  async ticket(id: number): Promise<Ticket> {
    return (await this.request<{ data: Ticket }>('GET', `/tickets/${id}`)).data;
  }

  async createTicket(projectId: number, payload: unknown): Promise<Ticket> {
    return (await this.request<{ data: Ticket }>('POST', `/projects/${projectId}/tickets`, payload)).data;
  }

  async updateTicket(id: number, payload: unknown): Promise<Ticket> {
    return (await this.request<{ data: Ticket }>('PATCH', `/tickets/${id}`, payload)).data;
  }

  async commentOnTicket(id: number, payload: unknown): Promise<{ comment: TicketComment; ticket: Ticket }> {
    return (await this.request<{ data: { comment: TicketComment; ticket: Ticket } }>('POST', `/tickets/${id}/comments`, payload)).data;
  }

  async submitReport(id: number, payload: unknown): Promise<Report> {
    return (await this.request<{ data: Report }>('POST', `/projects/${id}/reports`, payload)).data;
  }
}
