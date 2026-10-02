using System;
using System.Collections;
using System.Collections.Generic;
using System.Linq;
using System.Reflection;
using BepInEx;
using HarmonyLib;
using UnityEngine;

namespace Issue90Probe
{
    [BepInPlugin("phvalheim.issue90probe", "Issue90 Probe", "1.0.0")]
    public class Probe : BaseUnityPlugin
    {
        internal static BepInEx.Logging.ManualLogSource L;

        private void Awake()
        {
            L = Logger;
            L.LogWarning("[PROBE] armed");

            var h = new Harmony("phvalheim.issue90probe");

            // Priority far above everything else so no other gate can short-circuit us:
            // a silent probe would be indistinguishable from "no connection attempt".
            Patch(h, AccessTools.Method(typeof(ZNet), "RPC_PeerInfo"),
                  nameof(BeforePeerInfo), 10000, prefix: true);
            // Postfix at the very end, so it runs after OneMap's OnNewConnection postfix.
            Patch(h, AccessTools.Method(typeof(ZNet), "OnNewConnection"),
                  nameof(AfterOnNewConnection), -10000, prefix: false);
            Patch(h, AccessTools.Method(typeof(ZRpc), "Invoke",
                        new[] { typeof(string), typeof(object[]) }),
                  nameof(LogErrorInvoke), 10000, prefix: true);

            StartCoroutine(DumpLater());
        }

        private void Patch(Harmony h, MethodBase target, string name, int prio, bool prefix)
        {
            try
            {
                if (target == null) { L.LogError("[PROBE] target NOT FOUND for " + name); return; }
                var hm = new HarmonyMethod(AccessTools.Method(typeof(Probe), name)) { priority = prio };
                h.Patch(target, prefix: prefix ? hm : null, postfix: prefix ? null : hm);
                L.LogWarning("[PROBE] patched " + target.DeclaringType.Name + "." + target.Name
                             + " with " + name + " @priority=" + prio);
            }
            catch (Exception e) { L.LogError("[PROBE] patch " + name + " FAILED: " + e.Message); }
        }

        private IEnumerator DumpLater()
        {
            yield return new WaitForSeconds(45f);
            Dump(AccessTools.Method(typeof(ZNet), "RPC_PeerInfo"), "RPC_PeerInfo");
            Dump(AccessTools.Method(typeof(ZNet), "OnNewConnection"), "OnNewConnection");
            L.LogWarning("[PROBE] inventory dump complete");
        }

        private static void Dump(MethodBase m, string label)
        {
            try
            {
                if (m == null) { L.LogError("[PROBE] " + label + " not found"); return; }
                var info = Harmony.GetPatchInfo(m);
                if (info == null) { L.LogWarning("[PROBE] " + label + " has NO patches"); return; }
                Action<string, IEnumerable<Patch>> d = (kind, list) =>
                {
                    if (list == null) return;
                    foreach (var p in list.OrderByDescending(x => x.priority))
                        L.LogWarning(string.Format("[PROBE] {0} {1}: owner={2} priority={3} method={4}.{5} returns={6}",
                            label, kind, p.owner, p.priority,
                            p.PatchMethod.DeclaringType != null ? p.PatchMethod.DeclaringType.FullName : "?",
                            p.PatchMethod.Name, p.PatchMethod.ReturnType.Name));
                };
                d("PREFIX", info.Prefixes); d("POSTFIX", info.Postfixes); d("TRANSPILER", info.Transpilers);
            }
            catch (Exception e) { L.LogError("[PROBE] dump " + label + " failed: " + e); }
        }

        private static int Hash(string s) { return s.GetStableHashCode(); }

        // Is OneMap's OM_Version handler registered on this peer's ZRpc?
        private static string RpcState(ZRpc rpc)
        {
            try
            {
                var f = AccessTools.Field(typeof(ZRpc), "m_functions");
                var dict = f.GetValue(rpc) as IDictionary;
                if (dict == null) return "m_functions unreadable";
                bool om = dict.Contains(Hash("OM_Version"));
                return string.Format("registeredRpcCount={0} OM_VersionHandlerPresent={1}", dict.Count, om);
            }
            catch (Exception e) { return "err:" + e.Message; }
        }

        private static string GateState(ZRpc rpc)
        {
            try
            {
                var t = AppDomain.CurrentDomain.GetAssemblies()
                        .SelectMany(a => { try { return a.GetTypes(); } catch { return new Type[0]; } })
                        .FirstOrDefault(x => x.Name == "VersionGate");
                if (t == null) return "VersionGate type NOT LOADED";
                var fld = AccessTools.Field(t, "_validatedPeers");
                var set = fld != null ? fld.GetValue(null) as IEnumerable : null;
                int n = 0; bool has = false;
                if (set != null) foreach (var o in set) { n++; if (ReferenceEquals(o, rpc)) has = true; }
                return string.Format("_validatedPeers count={0} containsThisPeer={1} -> {2}",
                    n, has, has ? "WILL PASS" : "WILL BE REJECTED WITH ErrorVersion");
            }
            catch (Exception e) { return "err:" + e.Message; }
        }

        private static void AfterOnNewConnection(ZNetPeer peer, ZNet __instance)
        {
            try
            {
                L.LogWarning(string.Format("[PROBE] OnNewConnection DONE isServer={0} | {1}",
                    __instance.IsServer(), peer != null && peer.m_rpc != null ? RpcState(peer.m_rpc) : "no rpc"));
            }
            catch (Exception e) { L.LogError("[PROBE] OnNewConnection probe failed: " + e); }
        }

        private static void BeforePeerInfo(ZRpc rpc, ZNet __instance)
        {
            try
            {
                L.LogWarning("[PROBE] >>> RPC_PeerInfo arriving, isServer=" + __instance.IsServer());
                if (!__instance.IsServer()) return;
                L.LogWarning("[PROBE]     peer rpc: " + RpcState(rpc));
                L.LogWarning("[PROBE]     OneMap gate: " + GateState(rpc));
            }
            catch (Exception e) { L.LogError("[PROBE] gate read failed: " + e); }
        }

        private static void LogErrorInvoke(string method, object[] parameters)
        {
            try
            {
                if (method != "Error") return;
                int code = (parameters != null && parameters.Length > 0) ? Convert.ToInt32(parameters[0]) : -1;
                string name = Enum.IsDefined(typeof(ZNet.ConnectionStatus), code)
                    ? ((ZNet.ConnectionStatus)code).ToString() : "?";
                L.LogWarning(string.Format("[PROBE] >>> ZRpc.Invoke(\"Error\", {0}) -> {1}", code, name));
                L.LogWarning("[PROBE] stack:\n" + new System.Diagnostics.StackTrace(false));
            }
            catch { }
        }
    }
}
