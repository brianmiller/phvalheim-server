# Unraid and Kubernetes

Prefer an app template or Helm chart? These are alternatives to the [Docker Compose guide](install.md).

## Unraid

<img src="https://raw.githubusercontent.com/brianmiller/phvalheim-server/master/container/nginx/www/images/phvalheim_unraid_icon.svg" alt="PhValheim Unraid Icon" width="48" style="vertical-align:middle;"> Search for **PhValheim** in the Community Apps store.

> [!IMPORTANT]
> **Pin the `appdata` share to your pool, or Mover can empty PhValheim out from under it.**
>
> `/mnt/user/appdata/...` is the *user share* — a view that aggregates your pool **and** the
> array. `/mnt/cache/...` or `/mnt/<pool>/...` is the pool device itself. If the `appdata`
> share is allowed to move off the pool, Unraid's **Mover** relocates it to the array on its
> schedule. A container bind-mounted to the pool path is then pointing at an empty directory.
>
> Because `/opt/stateful` holds PhValheim's **database** as well as its worlds, the symptom is
> alarming and misleading: the admin dashboard comes up with **no worlds at all**, exactly as
> though it were a fresh install. Nothing has been deleted — the files are still on the array,
> visible under `/mnt/user/appdata/...`.
>
> Set **Shares → appdata → Primary storage: your pool, Secondary storage: None** (on older
> Unraid, *Use cache: Only*). This is Unraid's own recommendation for all `appdata`; databases
> do not survive being relocated mid-write. To check whether anything is currently stranded on
> the array, list `/mnt/user0/appdata/phvalheim-server/` — `/mnt/user0` is the array-only view,
> so anything it shows is **not** on your pool.

## Kubernetes / K3s

Clone the server repository first. Its Helm chart is in `helm/phvalheim/`.

```bash
git clone https://github.com/brianmiller/phvalheim-server.git
cd phvalheim-server
```

```bash
# Minimal install
helm install phvalheim ./helm/phvalheim/

# With Ingress for the public UI
helm install phvalheim ./helm/phvalheim/ \
  --set ingress.public.enabled=true \
  --set ingress.public.hosts[0].host=phvalheim.example.com \
  --set ingress.public.hosts[0].paths[0].path=/ \
  --set ingress.public.hosts[0].paths[0].pathType=Prefix

# With an existing PVC
helm install phvalheim ./helm/phvalheim/ \
  --set persistence.data.existingClaim=my-phvalheim-pvc
```

**How it works:**

- The pod runs with `hostNetwork: true` by default so Valheim's UDP game ports (25000-26000) bind directly to the node — no NodePort or LoadBalancer gymnastics required.
- Two ClusterIP Services are created: one for the **public UI** (8080) and one for the **admin UI** (8081). Each has an optional Ingress resource (disabled by default).
- A 20Gi PersistentVolumeClaim is created for `/opt/stateful`. An optional separate PVC for backups can be enabled with `persistence.backups.enabled=true`.
- Without Ingress enabled, access the admin UI via port-forward to run the Setup Wizard:

  ```bash
  kubectl port-forward svc/phvalheim-admin 8081:8081
  # Then open http://localhost:8081
  ```

See [Helm values](https://github.com/brianmiller/phvalheim-server/blob/master/helm/phvalheim/values.yaml) for the full set of configurable values.
