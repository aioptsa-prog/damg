# SLOs

- API latency P95 ≤ 300ms (internal) / 600ms (public admin)
- Worker heartbeat success ≥ 99.9% / 24h
- Job lease stuck rate < 0.5% / 24h

Collection:
- Synthetic monitor logs JSON lines at `storage/logs/synthetic.log`
- Dashboard can read last N samples to display simple bars
