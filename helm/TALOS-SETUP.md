# Talos K8s Cluster Setup for PhValheim

## Cluster Details

| | |
|---|---|
| **Cluster Name** | wopr |
| **Control Plane** | `2.2.20.20` (37648-talos1.phospher.com) |
| **Talos Version** | v1.12.4 |
| **Kubernetes Version** | v1.35.0 |
| **CNI** | Flannel (Talos default) |

## What Was Done to the Fresh Talos Node

### 1. Generated cluster config

```bash
talosctl gen config wopr https://2.2.20.20:6443 --output ~/.talos/
```

This created three files in `~/.talos/`:
- `controlplane.yaml` — config for control plane nodes
- `worker.yaml` — config for worker nodes
- `talosconfig` — client credentials for talosctl

### 2. Configured talosctl endpoints

```bash
talosctl --talosconfig ~/.talos/talosconfig config endpoint 2.2.20.20
talosctl --talosconfig ~/.talos/talosconfig config node 2.2.20.20
```

### 3. Applied control plane config

```bash
talosctl --talosconfig ~/.talos/talosconfig apply-config \
  --insecure --nodes 2.2.20.20 --file ~/.talos/controlplane.yaml
```

### 4. Bootstrapped Kubernetes

```bash
talosctl --talosconfig ~/.talos/talosconfig bootstrap --nodes 2.2.20.20
```

### 5. Retrieved kubeconfig

```bash
talosctl --talosconfig ~/.talos/talosconfig kubeconfig ~/.kube/config \
  --nodes 2.2.20.20 --force
```

### 6. Deployed local-path-provisioner (storage)

Fresh Talos has no StorageClass. We deployed Rancher's local-path-provisioner and set it as the default:

```bash
kubectl apply -f https://raw.githubusercontent.com/rancher/local-path-provisioner/v0.0.30/deploy/local-path-storage.yaml

kubectl patch storageclass local-path \
  -p '{"metadata": {"annotations":{"storageclass.kubernetes.io/is-default-class":"true"}}}'
```

The provisioner also needed a control-plane toleration (since there are no workers yet):

```bash
kubectl -n local-path-storage patch deployment local-path-provisioner \
  --type=json \
  -p='[{"op":"add","path":"/spec/template/spec/tolerations","value":[{"key":"node-role.kubernetes.io/control-plane","operator":"Exists","effect":"NoSchedule"}]}]'
```

And its helper pod config needed the same toleration:

```bash
# Edit the configmap to add the toleration to helperPod.yaml
kubectl -n local-path-storage edit configmap local-path-config
```

Add under `spec.tolerations`:
```yaml
- key: node-role.kubernetes.io/control-plane
  operator: Exists
  effect: NoSchedule
```

Then restart:
```bash
kubectl -n local-path-storage rollout restart deployment local-path-provisioner
```

### 7. Fixed PodSecurity namespace labels

Talos enforces PodSecurity standards by default. PhValheim requires `hostNetwork` and the provisioner uses `hostPath`, both of which violate the `baseline` policy. Two namespaces needed the `privileged` label:

```bash
kubectl label namespace default \
  pod-security.kubernetes.io/enforce=privileged \
  pod-security.kubernetes.io/warn=privileged --overwrite

kubectl label namespace local-path-storage \
  pod-security.kubernetes.io/enforce=privileged \
  pod-security.kubernetes.io/warn=privileged --overwrite
```

### 8. Deployed PhValheim via Helm

```bash
helm install phvalheim ./helm/phvalheim/ \
  --set 'tolerations[0].key=node-role.kubernetes.io/control-plane' \
  --set 'tolerations[0].operator=Exists' \
  --set 'tolerations[0].effect=NoSchedule'
```

The control-plane toleration is needed as long as workloads run on the control plane node.

---

## Adding Worker Nodes

### Prerequisites

You need the `worker.yaml` file generated in step 1 (stored at `~/.talos/worker.yaml`). This file contains the cluster secrets and is reusable across all worker nodes.

### Steps

#### 1. Apply the worker config to the new node

```bash
talosctl --talosconfig ~/.talos/talosconfig apply-config \
  --insecure --nodes <WORKER_IP> --file ~/.talos/worker.yaml
```

#### 2. Wait for the node to join the cluster

```bash
kubectl get nodes --watch
```

The new node should appear and transition to `Ready` within a couple of minutes.

#### 3. (Optional) Remove control-plane toleration from PhValheim

Once you have at least one worker node, you can redeploy PhValheim without the control-plane toleration so it schedules on a worker instead:

```bash
helm upgrade phvalheim ./helm/phvalheim/
```

Without the `--set tolerations[...]` flags, the pod will only schedule on worker nodes (which don't have the `NoSchedule` taint).

#### 4. (Optional) Remove control-plane tolerations from local-path-provisioner

If you want the provisioner to run on workers too, you can remove the toleration patches applied in step 6. Or leave them — having the provisioner able to run on any node is fine.

### Notes

- Worker nodes do **not** need the PodSecurity namespace fixes — those are namespace-level, not node-level, and are already applied.
- Worker nodes do **not** have the `NoSchedule` taint, so pods schedule on them by default.
- The `worker.yaml` contains shared cluster secrets. Keep it safe.
- Each worker gets its own IP. No additional config changes are needed per worker — Talos auto-joins via the cluster discovery mechanism.
- Storage: `local-path-provisioner` creates volumes on whichever node a pod is scheduled on. If PhValheim moves to a worker node, a **new PVC** will be provisioned there. To migrate data, either back up and restore through the PhValheim UI, or use a shared storage solution (NFS, Longhorn, etc.) instead of local-path.
