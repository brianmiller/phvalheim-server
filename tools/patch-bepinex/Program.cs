// BepInEx / MonoMod IL patchers for macOS arm64 compatibility.
//
// Commands:
//   patch-preloader <BepInEx.Preloader.dll>
//       Wraps RuntimeFix.Apply() methods in try-catch so BepInEx initializes
//       even when Harmony IL patching fails.
//
//   patch-monomod <MonoMod.Utils.dll>
//       Fixes ARM64 detection in PlatformHelper.DeterminePlatform():
//       Architecture.Arm64.HasFlag(Architecture.Arm) evaluates to false because
//       the Architecture enum is NOT [Flags] (Arm=1, Arm64=2). MonoMod never
//       sets Platform.ARM on Apple Silicon, so it picks DetourNativeX86Platform,
//       which writes x86 JMP bytes to arm64 machine code, crashing the selftest
//       and leaving DetourHelper.Runtime == null, making all Harmony patches throw.
//       Fix: after the HasFlag(Arm) check, also OR in Platform.ARM if running on Arm64.
//
//   inspect <MonoMod.Utils.dll>
//       Print the IL of PlatformHelper.DeterminePlatform() for debugging.

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using AsmResolver.DotNet;
using AsmResolver.DotNet.Code.Cil;
using AsmResolver.DotNet.Signatures;
using AsmResolver.PE.DotNet.Cil;
using AsmResolver.PE.DotNet.Metadata.Tables;
using AsmResolver.PE.DotNet.Metadata.Tables.Rows;

if (args.Length < 2) goto usage;

int result = args[0] switch
{
    "patch-preloader"      => PatchPreloader(args[1]),
    "patch-monomod"        => PatchMonoMod(args[1]),
    "patch-runtimedetour"  => PatchRuntimeDetour(args[1]),
    "inspect"              => Inspect(args[1]),
    "inspect-method"       => InspectMethod(args[1], args[2], args[3]),
    _                      => -1,
};
if (result == -1) goto usage;
return result;

usage:
Console.Error.WriteLine("Usage:");
Console.Error.WriteLine("  patch-preloader     <BepInEx.Preloader.dll>");
Console.Error.WriteLine("  patch-monomod       <MonoMod.Utils.dll>");
Console.Error.WriteLine("  patch-runtimedetour <MonoMod.RuntimeDetour.dll>");
Console.Error.WriteLine("  inspect             <MonoMod.Utils.dll>");
Console.Error.WriteLine("  inspect-method      <dll> <TypeName> <MethodName>");
return 1;


// ── patch-preloader ────────────────────────────────────────────────────────────
static int PatchPreloader(string dllPath)
{
    if (!File.Exists(dllPath)) { Console.Error.WriteLine($"Not found: {dllPath}"); return 1; }
    string bak = dllPath + ".orig";
    if (!File.Exists(bak)) { File.Copy(dllPath, bak); Console.WriteLine($"Backup: {bak}"); }

    var module = ModuleDefinition.FromFile(dllPath);
    var runtimeFixTypes = module.TopLevelTypes
        .Concat(module.TopLevelTypes.SelectMany(t => t.NestedTypes))
        .Where(t => t.Namespace == "BepInEx.Preloader.RuntimeFixes").ToList();

    Console.WriteLine($"Found {runtimeFixTypes.Count} RuntimeFix type(s)");
    var corlibScope = module.CorLibTypeFactory.CorLibScope;
    var exceptionRef = new TypeReference(module, corlibScope, "System", "Exception");
    int patched = 0;

    foreach (var fixType in runtimeFixTypes)
    {
        var apply = fixType.Methods.FirstOrDefault(m => m.Name == "Apply");
        if (apply?.CilMethodBody == null) continue;
        if (apply.CilMethodBody.ExceptionHandlers.Count > 0) { Console.WriteLine($"  {fixType.Name}.Apply() already patched"); continue; }
        Console.WriteLine($"  Patching {fixType.Name}.Apply()...");
        var instrs = apply.CilMethodBody.Instructions;
        var ret = instrs.LastOrDefault(i => i.OpCode == CilOpCodes.Ret);
        if (ret == null) continue;
        var catchPop = new CilInstruction(CilOpCodes.Pop);
        var catchRet = new CilInstruction(CilOpCodes.Ret);
        ret.ReplaceWith(CilOpCodes.Leave_S, catchRet.CreateLabel());
        instrs.Add(catchPop); instrs.Add(catchRet);
        apply.CilMethodBody.ExceptionHandlers.Add(new CilExceptionHandler {
            HandlerType = CilExceptionHandlerType.Exception,
            TryStart = instrs[0].CreateLabel(), TryEnd = catchPop.CreateLabel(),
            HandlerStart = catchPop.CreateLabel(), HandlerEnd = catchRet.CreateLabel(),
            ExceptionType = exceptionRef,
        });
        patched++;
    }
    if (patched == 0) { Console.WriteLine("Nothing to patch."); return 0; }
    string tmp = dllPath + ".patched"; module.Write(tmp); File.Move(tmp, dllPath, overwrite: true);
    Console.WriteLine($"Done. Patched {patched} method(s).");
    return 0;
}


// ── inspect ────────────────────────────────────────────────────────────────────
static int Inspect(string dllPath)
{
    if (!File.Exists(dllPath)) { Console.Error.WriteLine($"Not found: {dllPath}"); return 1; }
    var module = ModuleDefinition.FromFile(dllPath);
    var ph = module.TopLevelTypes.FirstOrDefault(t => t.Name == "PlatformHelper");
    if (ph == null) { Console.Error.WriteLine("PlatformHelper not found"); return 1; }
    Console.WriteLine("Methods: " + string.Join(", ", ph.Methods.Select(m => m.Name)));
    var det = ph.Methods.FirstOrDefault(m => m.Name == "DeterminePlatform");
    if (det?.CilMethodBody == null) { Console.Error.WriteLine("DeterminePlatform not found"); return 1; }
    var instrs = det.CilMethodBody.Instructions;
    Console.WriteLine($"\nDeterminePlatform ({instrs.Count} instructions):");
    for (int i = 0; i < instrs.Count; i++)
        Console.WriteLine($"  {i:000}: {instrs[i]}");
    return 0;
}


// ── inspect-method ─────────────────────────────────────────────────────────────
static int InspectMethod(string dllPath, string typeName, string methodName)
{
    if (!File.Exists(dllPath)) { Console.Error.WriteLine($"Not found: {dllPath}"); return 1; }
    var module = ModuleDefinition.FromFile(dllPath);
    var allTypes = module.TopLevelTypes.Concat(module.TopLevelTypes.SelectMany(t => t.NestedTypes)).ToList();
    var type = allTypes.FirstOrDefault(t => t.Name == typeName || t.FullName == typeName)
            ?? allTypes.FirstOrDefault(t => t.FullName.Contains(typeName));
    if (type == null) { Console.Error.WriteLine($"Type '{typeName}' not found. Types: {string.Join(", ", allTypes.Select(t => t.Name).Take(20))}"); return 1; }
    Console.WriteLine($"Type: {type.FullName}");
    Console.WriteLine($"Methods: {string.Join(", ", type.Methods.Select(m => m.Name))}");
    var method = type.Methods.FirstOrDefault(m => m.Name == methodName || m.Name.Contains(methodName));
    if (method?.CilMethodBody == null) { Console.Error.WriteLine($"Method '{methodName}' not found or no IL body"); return 1; }
    var instrs = method.CilMethodBody.Instructions;
    Console.WriteLine($"\n{method.Name} ({instrs.Count} instructions):");
    for (int i = 0; i < instrs.Count; i++)
        Console.WriteLine($"  {i:000}: {instrs[i]}");
    var handlers = method.CilMethodBody.ExceptionHandlers;
    if (handlers.Count > 0)
    {
        Console.WriteLine($"\nException handlers ({handlers.Count}):");
        foreach (var eh in handlers)
            Console.WriteLine($"  {eh.HandlerType}: try=[{eh.TryStart}..{eh.TryEnd}) handler=[{eh.HandlerStart}..{eh.HandlerEnd}) exType={eh.ExceptionType}");
    }
    return 0;
}


// ── patch-runtimedetour ────────────────────────────────────────────────────────
static int PatchRuntimeDetour(string dllPath)
{
    // Patches MonoMod.RuntimeDetour.dll to also try libmonobdwgc-2.0.dylib when
    // libmonosgen-2.0.dylib is not found. Unity ships the Boehm GC variant (bdwgc)
    // while standalone Mono ships the SGen GC variant (sgen).
    //
    // When DetourNativeMonoPlatform loads successfully, it uses mono_mprotect which
    // correctly handles JIT memory pages on macOS (including Apple Silicon W^X).
    // Without it, DetourNativeLibcPlatform is used, which calls mprotect(RWX) that
    // always fails on Apple Silicon, making the selftest throw, leaving Runtime==null.
    //
    // The patch: in DetourHelper.get_Native(), after the libmonosgen try-catch block,
    // add an identical try-catch that passes "libmonobdwgc-2.0.{suffix}" instead.
    //
    // Original IL around the libmonosgen try block (find via ldstr "libmonosgen-2.0."):
    //   ldstr "libmonosgen-2.0."           ← find this
    //   ... PlatformHelper.LibrarySuffix ...
    //   ... string.Concat ...
    //   newobj DetourNativeMonoPlatform..ctor(inner, libname)
    //   stsfld _Native
    //   ret                                 ← or leave/br to after catch
    // catch block: pop/leave
    //
    // We duplicate the entire try block but replace "libmonosgen-2.0." with "libmonobdwgc-2.0."

    if (!File.Exists(dllPath)) { Console.Error.WriteLine($"Not found: {dllPath}"); return 1; }
    string bak = dllPath + ".orig";
    if (!File.Exists(bak)) { File.Copy(dllPath, bak); Console.WriteLine($"Backup: {bak}"); }

    var module = ModuleDefinition.FromFile(dllPath);
    var allTypes = module.TopLevelTypes.Concat(module.TopLevelTypes.SelectMany(t => t.NestedTypes)).ToList();
    var detourHelper = allTypes.FirstOrDefault(t => t.Name == "DetourHelper");
    if (detourHelper == null) { Console.Error.WriteLine("DetourHelper not found"); return 1; }

    var getNative = detourHelper.Methods.FirstOrDefault(m => m.Name == "get_Native" && m.CilMethodBody != null);
    if (getNative == null) { Console.Error.WriteLine("get_Native not found"); return 1; }

    var instrs = getNative.CilMethodBody.Instructions;

    // Already patched?
    if (instrs.Any(i => i.OpCode == CilOpCodes.Ldstr && i.Operand is string s0 && s0.Contains("libmonobdwgc")))
    { Console.WriteLine("Already patched."); return 0; }

    // Find ldstr "libmonosgen-2.0."
    int sgenIdx = -1;
    for (int i = 0; i < instrs.Count; i++)
        if (instrs[i].OpCode == CilOpCodes.Ldstr && instrs[i].Operand is string s && s.Contains("libmonosgen"))
        { sgenIdx = i; break; }
    if (sgenIdx < 0) { Console.Error.WriteLine("ldstr 'libmonosgen' not found"); return 1; }
    Console.WriteLine($"Found libmonosgen ldstr at instr {sgenIdx}");

    // From the known IL structure (inspected):
    //   sgenIdx-1: ldloc.1                      (inner native platform)
    //   sgenIdx+0: ldstr "libmonosgen-2.0."
    //   sgenIdx+1: call get_LibrarySuffix
    //   sgenIdx+2: call String.Concat
    //   sgenIdx+3: newobj DetourNativeMonoPlatform
    //   sgenIdx+4: dup
    //   sgenIdx+5: stsfld _Native
    //   sgenIdx+6: stloc.3
    //   sgenIdx+7: leave.s [return]    ← end of try body
    //   sgenIdx+8: pop                 ← catch start
    //   sgenIdx+9: leave.s [next]      ← catch end → jump to MonoPosix block (sgenIdx+10)

    int tryStart   = sgenIdx - 1; // ldloc.1
    int tryEnd     = sgenIdx + 7; // leave.s
    int catchEnd   = sgenIdx + 9; // leave.s
    int insertAt   = sgenIdx + 10; // = instruction AFTER the catch block

    Console.WriteLine($"Block: try=[{tryStart}..{tryEnd}], catch=[{sgenIdx+8}..{catchEnd}], insertAt={insertAt}");

    // Get the target of the catch leave (= MonoPosix block = instrs[insertAt])
    var nextBlockInstr = instrs[insertAt];

    // Clone try body (tryStart..tryEnd) replacing libmonosgen→libmonobdwgc
    var cloned = new List<CilInstruction>();
    for (int i = tryStart; i <= tryEnd; i++)
    {
        var src = instrs[i];
        CilInstruction clone;
        if (src.OpCode == CilOpCodes.Ldstr && src.Operand is string ls)
            clone = new CilInstruction(CilOpCodes.Ldstr, ls.Replace("libmonosgen", "libmonobdwgc"));
        else
            clone = new CilInstruction(src.OpCode, src.Operand);
        cloned.Add(clone);
    }

    // Use Leave (long form) to avoid short-branch overflow after inserting extra instructions
    // Also upgrade any Leave_S in the cloned body to Leave
    foreach (var c in cloned)
        if (c.OpCode == CilOpCodes.Leave_S) c.OpCode = CilOpCodes.Leave;

    // Also upgrade the original leave.s at tryEnd to Leave (it may now be too far)
    if (instrs[tryEnd].OpCode == CilOpCodes.Leave_S) instrs[tryEnd].OpCode = CilOpCodes.Leave;

    // Capture return label BEFORE Part 1 inserts instructions (indices shift after insert)
    var returnLabel = (ICilLabel)instrs[tryEnd].Operand!;

    var newCatchPop   = new CilInstruction(CilOpCodes.Pop);
    var newCatchLeave = new CilInstruction(CilOpCodes.Leave, nextBlockInstr.CreateLabel());

    // Insert all new instructions at insertAt
    int pos = insertAt;
    foreach (var c in cloned) instrs.Insert(pos++, c);
    instrs.Insert(pos++, newCatchPop);
    instrs.Insert(pos,   newCatchLeave);

    // Add exception handler
    getNative.CilMethodBody.ExceptionHandlers.Add(new CilExceptionHandler
    {
        HandlerType   = CilExceptionHandlerType.Exception,
        TryStart      = cloned[0].CreateLabel(),
        TryEnd        = newCatchPop.CreateLabel(),
        HandlerStart  = newCatchPop.CreateLabel(),
        HandlerEnd    = newCatchLeave.CreateLabel(),
        ExceptionType = new TypeReference(module, module.CorLibTypeFactory.CorLibScope, "System", "Exception"),
    });

    // CRITICAL: Redirect the original catch's leave to Part 1's try start.
    // Without this, the original catch does pop → leave [MonoPosix], skipping Part 1 entirely.
    instrs[catchEnd].OpCode = CilOpCodes.Br;
    instrs[catchEnd].Operand = cloned[0].CreateLabel();

    string tmp = dllPath + ".patched";
    module.Write(tmp);
    File.Move(tmp, dllPath, overwrite: true);
    Console.WriteLine("Part 1 done: libmonobdwgc-2.0 fallback added.");

    // ── Part 1b: Also try DOORSTOP_MONO_LIB_PATH env var as library path ─────────
    // DynDll resolves library names via dlopen() which only searches standard paths.
    // libmonobdwgc-2.0.dylib lives in Valheim.app/Contents/Frameworks/ which is not
    // in DYLD_LIBRARY_PATH, so the Part 1 name-only lookup fails.
    // Solution: also try the value of DOORSTOP_MONO_LIB_PATH which doorstop sets to
    // the FULL PATH of the Mono library — this is exactly what we need.
    //
    // Insert after the bdwgc try block: another try block that does:
    //   string path = Environment.GetEnvironmentVariable("DOORSTOP_MONO_LIB_PATH");
    //   if (!string.IsNullOrEmpty(path))
    //       return _Native = new DetourNativeMonoPlatform(native, path);
    //
    // We find the insertion point = instruction after the bdwgc catch block (= the MonoPosixHelper block start)

    // Find ldstr "DOORSTOP_MONO_LIB_PATH" if already there
    bool part1bAlready = getNative.CilMethodBody!.Instructions
        .Any(i => i.OpCode == CilOpCodes.Ldstr && i.Operand is string s2 && s2 == "DOORSTOP_MONO_LIB_PATH");

    if (part1bAlready)
    {
        Console.WriteLine("Part 1b: already patched.");
    }
    else
    {
        // The bdwgc catch block ends at insertAt (the MonoPosixHelper ldstr "MONOMOD..." block)
        // We need to insert a new try block just before insertAt
        // Reuse the same insertAt from Part 1
        var insertAt1b = nextBlockInstr; // MonoPosixHelper block start (captured before Part 1 shifted indices)

        // Import needed types
        var stringType = module.CorLibTypeFactory.String;
        var envClass   = new TypeReference(module, module.CorLibTypeFactory.CorLibScope, "System", "Environment");
        var getEnvMethod = new MemberReference(envClass, "GetEnvironmentVariable",
            MethodSignature.CreateStatic(stringType, stringType));
        var isNullOrEmptyMethod = new MemberReference(
            new TypeReference(module, module.CorLibTypeFactory.CorLibScope, "System", "String"),
            "IsNullOrEmpty",
            MethodSignature.CreateStatic(module.CorLibTypeFactory.Boolean, stringType));

        var importedGetEnv      = module.DefaultImporter.ImportMethod(getEnvMethod);
        var importedIsNullEmpty = module.DefaultImporter.ImportMethod(isNullOrEmptyMethod);

        // Find DetourNativeMonoPlatform..ctor reference from the bdwgc try block we added earlier
        // (it's the newobj instruction in our cloned block)
        IMethodDescriptor? monoPlatformCtor = null;
        for (int k = instrs.Count - 1; k >= 0; k--)
        {
            var ci3 = instrs[k];
            if (ci3.OpCode == CilOpCodes.Newobj && ci3.Operand?.ToString()?.Contains("DetourNativeMonoPlatform") == true)
            { monoPlatformCtor = ci3.Operand as IMethodDescriptor; break; }
        }

        if (monoPlatformCtor == null) { Console.Error.WriteLine("DetourNativeMonoPlatform ctor ref not found, skipping 1b"); goto part2; }

        // Find the _Native stsfld reference
        IFieldDescriptor? nativeField = null;
        for (int k = instrs.Count - 1; k >= 0; k--)
        {
            var ci3 = instrs[k];
            if ((ci3.OpCode == CilOpCodes.Stsfld) && ci3.Operand?.ToString()?.Contains("_Native") == true)
            { nativeField = ci3.Operand as IFieldDescriptor; break; }
        }

        if (nativeField == null) { Console.Error.WriteLine("_Native stsfld ref not found, skipping 1b"); goto part2; }

        // Build the try block:
        //   ldloc.1                           (inner native platform)
        //   ldstr "DOORSTOP_MONO_LIB_PATH"
        //   call Environment.GetEnvironmentVariable
        //   dup
        //   call String.IsNullOrEmpty
        //   brtrue.s [leave_after]            (if null/empty, skip)
        //   newobj DetourNativeMonoPlatform..ctor
        //   dup
        //   stsfld _Native
        //   stloc.3
        //   leave [return]
        // [leave_after]:
        //   pop                               (pop the non-null string we didn't use)
        //   leave [insertAt1b]               (exit try, continue to MonoPosix)
        // [catch]:
        //   pop
        //   leave [insertAt1b]

        var ldNative1b  = new CilInstruction(CilOpCodes.Ldloc_1);
        var ldStr1b     = new CilInstruction(CilOpCodes.Ldstr, "DOORSTOP_MONO_LIB_PATH");
        var callGetEnv  = new CilInstruction(CilOpCodes.Call, importedGetEnv);
        var dup1b       = new CilInstruction(CilOpCodes.Dup);
        var callIsNull  = new CilInstruction(CilOpCodes.Call, importedIsNullEmpty);
        var popIfNull   = new CilInstruction(CilOpCodes.Pop);  // pop the string when IsNullOrEmpty is true
        var leaveIfNull = new CilInstruction(CilOpCodes.Leave, insertAt1b.CreateLabel());
        // When not null: stack has string, newobj needs 2 args (native + string)
        // But wait: after dup + brtrue, if we jump we have the string on stack...
        // Let me rethink the stack manipulation:
        // Stack at callGetEnv result: [string]
        // dup: [string, string]
        // callIsNull: [string, bool]
        // brtrue.s [skipBlock]: [string] if null/empty → pop string, leave
        //                       [string] if not empty → proceed to newobj

        // Actually, need to pop the duplicated string when IsNullOrEmpty=true, then leave:
        var brtrue1b    = new CilInstruction(CilOpCodes.Brtrue, popIfNull.CreateLabel()); // replace later with correct target
        var newobj1b    = new CilInstruction(CilOpCodes.Newobj, monoPlatformCtor);
        var dup2        = new CilInstruction(CilOpCodes.Dup);
        var stsfld1b    = new CilInstruction(CilOpCodes.Stsfld, nativeField);
        var stloc1b     = new CilInstruction(CilOpCodes.Stloc_3);
        var leave1bRet  = new CilInstruction(CilOpCodes.Leave, returnLabel); // leave to return
        var leave1bSkip = new CilInstruction(CilOpCodes.Leave, insertAt1b.CreateLabel());
        var catch1bPop  = new CilInstruction(CilOpCodes.Pop);
        var catch1bLeave = new CilInstruction(CilOpCodes.Leave, insertAt1b.CreateLabel());

        // Fix the brtrue target: jump to popIfNull
        brtrue1b.Operand = popIfNull.CreateLabel();

        // returnLabel was captured before Part 1 inserted instructions; already set above.

        // Insert order: ldNative1b, ldStr1b, callGetEnv, dup1b, callIsNull, brtrue1b (→popIfNull),
        //   ldloc.1 (WAIT - we consumed ldloc.1 already and string is on stack)
        // Actually the stack situation is complex. Let me simplify:
        // Don't dup the getenv result. Instead:
        // ldloc.1
        // ldstr "DOORSTOP_MONO_LIB_PATH"
        // call GetEnvironmentVariable
        // dup → [str, str]
        // brtrue.s [hasValue] → [str]
        // pop → []
        // leave [skip]
        // [hasValue]:
        //   store in local → pop from stack (we'll reload ldloc.1 and ldstr)
        //   pop → []... hmm this is getting complex

        // Simplest approach: store the env var result in a temp local (stloc.s V_new)
        // But we can't add locals easily.
        // Alternative: just call GetEnvironmentVariable TWICE (once for null check, once for value)

        // Even simpler - just try/catch the whole thing:
        // try {
        //   return _Native = new DetourNativeMonoPlatform(native, Environment.GetEnvironmentVariable("DOORSTOP_MONO_LIB_PATH") ?? "");
        // } catch {}

        // Let's do it without the null check - just try with the env var and if it's null/empty, DetourNativeMonoPlatform constructor will fail and catch handles it

        // Simplified try block:
        //   ldloc.1
        //   ldstr "DOORSTOP_MONO_LIB_PATH"
        //   call GetEnvironmentVariable
        //   newobj DetourNativeMonoPlatform..ctor(inner, envval)
        //   dup
        //   stsfld _Native
        //   stloc.3
        //   leave [return]
        // catch: pop, leave [insertAt1b]

        // Remove all the complex null-check stuff and just use the simplified approach:
        var part1bInstrs = new List<CilInstruction>
        {
            new CilInstruction(CilOpCodes.Ldloc_1),           // native
            new CilInstruction(CilOpCodes.Ldstr, "DOORSTOP_MONO_LIB_PATH"),
            new CilInstruction(CilOpCodes.Call, importedGetEnv),
            new CilInstruction(CilOpCodes.Newobj, monoPlatformCtor),  // new DetourNativeMonoPlatform(native, envval)
            new CilInstruction(CilOpCodes.Dup),
            new CilInstruction(CilOpCodes.Stsfld, nativeField),
            new CilInstruction(CilOpCodes.Stloc_3),
            new CilInstruction(CilOpCodes.Leave, returnLabel), // leave to return
        };
        var part1bCatchPop  = new CilInstruction(CilOpCodes.Pop);
        var part1bCatchLeave = new CilInstruction(CilOpCodes.Leave, insertAt1b.CreateLabel());

        // Need to upgrade any short leaves to long form in this area (already done partially)
        int pos1b = instrs.IndexOf(insertAt1b);
        foreach (var c in part1bInstrs) instrs.Insert(pos1b++, c);
        instrs.Insert(pos1b++, part1bCatchPop);
        instrs.Insert(pos1b, part1bCatchLeave);

        getNative.CilMethodBody.ExceptionHandlers.Add(new CilExceptionHandler
        {
            HandlerType   = CilExceptionHandlerType.Exception,
            TryStart      = part1bInstrs[0].CreateLabel(),
            TryEnd        = part1bCatchPop.CreateLabel(),
            HandlerStart  = part1bCatchPop.CreateLabel(),
            HandlerEnd    = insertAt1b.CreateLabel(),
            ExceptionType = new TypeReference(module, module.CorLibTypeFactory.CorLibScope, "System", "Exception"),
        });

        // CRITICAL: Redirect Part 1's catch leave to Part 1b's try start.
        // Without this, Part 1's catch does pop → leave [MonoPosix], skipping Part 1b.
        newCatchLeave.OpCode = CilOpCodes.Br;
        newCatchLeave.Operand = part1bInstrs[0].CreateLabel();

        Console.WriteLine("Part 1b: DOORSTOP_MONO_LIB_PATH try block added.");
    }
    part2:

    // ── Part 2: Wrap _HookSelftest calls in DetourRuntimeILPlatform..ctor ─────────
    // On macOS arm64 (Apple Silicon), JIT pages use W^X enforcement via
    // pthread_jit_write_protect_np. Regular mprotect(RWX) fails (EACCES), and
    // DetourNativeARMPlatform.MakeWritable is a no-op — so when _HookSelftest
    // tries to write ARM64 branch bytes to the test method's native code, it gets
    // SIGBUS/exception. This makes DetourRuntimeMonoPlatform constructor throw,
    // leaving DetourHelper.Runtime == null and ALL Harmony patches fail (NRE).
    //
    // Fix: wrap both _HookSelftest calls in DetourRuntimeILPlatform..ctor with
    // try-catch. If the selftest throws, Runtime is still set to a valid non-null
    // instance (with default struct-return handling values), enabling Harmony patches
    // to work for the vast majority of methods.

    var runtimeILPlatform = allTypes.FirstOrDefault(t => t.Name == "DetourRuntimeILPlatform");
    if (runtimeILPlatform == null) { Console.Error.WriteLine("DetourRuntimeILPlatform not found"); return 1; }

    var ctor = runtimeILPlatform.Methods.FirstOrDefault(m => m.Name == ".ctor" && m.CilMethodBody != null);
    if (ctor == null) { Console.Error.WriteLine("DetourRuntimeILPlatform..ctor not found"); return 1; }

    var ctorInstrs = ctor.CilMethodBody.Instructions;
    Console.WriteLine($"DetourRuntimeILPlatform..ctor: {ctorInstrs.Count} instructions");

    // Find the two _HookSelftest call instructions
    var hookCalls = new List<int>();
    for (int i = 0; i < ctorInstrs.Count; i++)
        if ((ctorInstrs[i].OpCode == CilOpCodes.Call || ctorInstrs[i].OpCode == CilOpCodes.Callvirt) &&
            ctorInstrs[i].Operand?.ToString()?.Contains("_HookSelftest") == true)
            hookCalls.Add(i);

    Console.WriteLine($"Found {hookCalls.Count} _HookSelftest call(s) at indices: {string.Join(", ", hookCalls)}");
    if (hookCalls.Count == 0) { Console.Error.WriteLine("No _HookSelftest calls found"); return 1; }

    // Already patched?
    if (ctor.CilMethodBody.ExceptionHandlers.Any())
    { Console.WriteLine("Already has exception handlers — checking count"); }

    var exceptionRef2 = new TypeReference(module, module.CorLibTypeFactory.CorLibScope, "System", "Exception");

    // Wrap each _HookSelftest call in its own try-catch (process in reverse to keep indices valid)
    int addedHandlers = 0;
    foreach (int callIdx in hookCalls.OrderByDescending(x => x))
    {
        var callInstr = ctorInstrs[callIdx];
        // Check if already wrapped
        if (ctor.CilMethodBody.ExceptionHandlers.Any(h => h.TryStart?.Offset <= callInstr.Offset && h.TryEnd?.Offset > callInstr.Offset))
        {
            Console.WriteLine($"  _HookSelftest at {callIdx} already wrapped, skipping");
            continue;
        }

        // _HookSelftest takes (this, from, to) — 3 args pushed before the call.
        // SEH requires the try block to start with an empty stack, so we include
        // the 3 arg-push instructions (callIdx-3 .. callIdx) in the try body.
        int tryStartIdx = callIdx - 3;
        var tryStartInstr = ctorInstrs[tryStartIdx];
        var afterCall     = ctorInstrs[callIdx + 1];

        // Create: leave.s [afterCall], pop, leave.s [afterCall]
        var leaveInstr  = new CilInstruction(CilOpCodes.Leave_S, afterCall.CreateLabel());
        var catchPop2   = new CilInstruction(CilOpCodes.Pop);
        var catchLeave2 = new CilInstruction(CilOpCodes.Leave_S, afterCall.CreateLabel());

        // Insert after the call: leave, catch-pop, catch-leave
        ctorInstrs.Insert(callIdx + 1, leaveInstr);
        ctorInstrs.Insert(callIdx + 2, catchPop2);
        ctorInstrs.Insert(callIdx + 3, catchLeave2);

        // Add exception handler: try = [tryStartInstr .. catchPop2], catch = [catchPop2 .. afterCall]
        ctor.CilMethodBody.ExceptionHandlers.Add(new CilExceptionHandler
        {
            HandlerType   = CilExceptionHandlerType.Exception,
            TryStart      = tryStartInstr.CreateLabel(),
            TryEnd        = catchPop2.CreateLabel(),
            HandlerStart  = catchPop2.CreateLabel(),
            HandlerEnd    = afterCall.CreateLabel(),
            ExceptionType = exceptionRef2,
        });

        Console.WriteLine($"  Wrapped _HookSelftest at {callIdx}");
        addedHandlers++;
    }

    if (addedHandlers == 0) { Console.WriteLine("No new handlers needed."); }

    Console.WriteLine("Part 2 done: individual _HookSelftest wraps added (belt).");

    // ── Part 2b: Big try-catch around entire selftest block ─────────────────────
    // After instruction 7 (call Object::.ctor) the stack is empty.
    // Wrap everything from instruction 8 to the instruction before ret in a single
    // try-catch. If ANY part of the selftest block throws (including the Invoke()
    // calls that assert "This method should've been detoured!"), we catch it and
    // the constructor completes normally with default calibration values.
    // This is the suspenders to the belt (Part 2's per-call catches).
    // After each wrapped _HookSelftest, the constructor calls Invoke() on a delegate
    // to check the selftest result. If the hook wasn't applied, _SelftestGetRefPtr()
    // throws "This method should've been detoured!". Wrap these Invoke() calls so the
    // constructor uses a default value (IntPtr.Zero) when the selftest wasn't applied.
    //
    // The pattern: callvirt !0 Func`1<IntPtr>::Invoke() followed by stloc.X
    // We wrap: try { callvirt Invoke(); stloc.X } catch { ldc.i4.0; conv.i; stloc.X }

    var ctorInstrs2 = ctor.CilMethodBody.Instructions;

    // Find the ret instruction (last one)
    var ctorRet = ctorInstrs2.Last(i2 => i2.OpCode == CilOpCodes.Ret);

    // Big try body: instructions 8 .. (ctorRet - 1)
    // instruction 7 = call Object::.ctor() → stack empty after → safe try start at 8
    var bigTryStart = ctorInstrs2[8];  // first instruction after base ctor call

    // Already has a big outer try? Check if instr 8 is already a try start
    bool hasBigTry = ctor.CilMethodBody.ExceptionHandlers.Any(
        h => h.TryStart?.Offset == bigTryStart.Offset);

    if (hasBigTry)
    {
        Console.WriteLine("Part 2b: big try already present, skipping.");
    }
    else
    {
        // Insert before ret: leave.s [ret], pop, leave.s [ret]
        var bigLeave  = new CilInstruction(CilOpCodes.Leave, ctorRet.CreateLabel());
        var bigPop    = new CilInstruction(CilOpCodes.Pop);
        var bigLeave2 = new CilInstruction(CilOpCodes.Leave, ctorRet.CreateLabel());

        int retIdx = ctorInstrs2.IndexOf(ctorRet);

        // Redirect any branch inside [bigTryStart..ret) that jumps directly to ctorRet.
        // CIL forbids jumping out of a try block with brXXX — only 'leave' is valid.
        // Example: "brfalse IL_0200" (skip DynamicMethodDef if not available) would
        // branch straight to ret, which is outside our new try block → JIT rejects the method.
        // Fix: redirect those branches to bigLeave, which properly exits the try.
        int tryBodyStart = ctorInstrs2.IndexOf(bigTryStart);
        for (int k = tryBodyStart; k < retIdx; k++)
        {
            var ci = ctorInstrs2[k];
            if (ci.Operand is CilInstructionLabel lbl && lbl.Instruction == ctorRet)
            {
                ci.Operand = bigLeave.CreateLabel();
                Console.WriteLine($"  Part 2b: redirected instr {k} ({ci.OpCode}) → bigLeave");
            }
        }

        ctorInstrs2.Insert(retIdx, bigLeave2);
        ctorInstrs2.Insert(retIdx, bigPop);
        ctorInstrs2.Insert(retIdx, bigLeave);

        ctor.CilMethodBody.ExceptionHandlers.Add(new CilExceptionHandler
        {
            HandlerType   = CilExceptionHandlerType.Exception,
            TryStart      = bigTryStart.CreateLabel(),
            TryEnd        = bigPop.CreateLabel(),
            HandlerStart  = bigPop.CreateLabel(),
            HandlerEnd    = ctorRet.CreateLabel(),
            ExceptionType = exceptionRef2,
        });

        // Upgrade all short branches in ctor to long form to prevent overflow
        foreach (var ci2 in ctorInstrs2)
        {
            if (ci2.OpCode == CilOpCodes.Leave_S)  ci2.OpCode = CilOpCodes.Leave;
            if (ci2.OpCode == CilOpCodes.Br_S)     ci2.OpCode = CilOpCodes.Br;
            if (ci2.OpCode == CilOpCodes.Brfalse_S) ci2.OpCode = CilOpCodes.Brfalse;
            if (ci2.OpCode == CilOpCodes.Brtrue_S)  ci2.OpCode = CilOpCodes.Brtrue;
        }

        Console.WriteLine("Part 2b done: big try-catch around entire selftest block added.");
    }

    // ── Part 3: Fix get_Runtime to retry when _RuntimeInit=true but _Runtime=null ────
    // _RuntimeInit is set to true BEFORE calling the constructor. If constructor throws,
    // _RuntimeInit=true but _Runtime=null. Next call: sees _RuntimeInit=true → returns null.
    // Fix: if _RuntimeInit=true but _Runtime==null, reset _RuntimeInit to allow retry.

    var getRuntime = detourHelper.Methods.FirstOrDefault(m => m.Name == "get_Runtime" && m.CilMethodBody != null);
    if (getRuntime == null) { Console.Error.WriteLine("get_Runtime not found, skipping Part 3"); goto writePart2; }

    var rtInstrs = getRuntime.CilMethodBody.Instructions;

    // Find: brfalse + ldnull pattern (instructions 14 and 15 from inspection)
    int brFalseIdx = -1;
    for (int i = 0; i < rtInstrs.Count - 1; i++)
        if ((rtInstrs[i].OpCode == CilOpCodes.Brfalse_S || rtInstrs[i].OpCode == CilOpCodes.Brfalse) &&
            rtInstrs[i + 1].OpCode == CilOpCodes.Ldnull)
        { brFalseIdx = i; break; }

    if (brFalseIdx < 0) { Console.Error.WriteLine("get_Runtime: brfalse+ldnull not found, skipping Part 3"); goto writePart2; }

    int ldNullIdx = brFalseIdx + 1;
    Console.WriteLine($"Part 3: brfalse at {brFalseIdx}, ldnull at {ldNullIdx}");

    // Upgrade all short branches in get_Runtime to avoid overflow after inserting 5 instructions
    foreach (var instr in rtInstrs)
    {
        if (instr.OpCode == CilOpCodes.Leave_S) instr.OpCode = CilOpCodes.Leave;
        if (instr.OpCode == CilOpCodes.Br_S) instr.OpCode = CilOpCodes.Br;
        if (instr.OpCode == CilOpCodes.Brfalse_S) instr.OpCode = CilOpCodes.Brfalse;
        if (instr.OpCode == CilOpCodes.Brtrue_S) instr.OpCode = CilOpCodes.Brtrue;
        if (instr.OpCode == CilOpCodes.Beq_S) instr.OpCode = CilOpCodes.Beq;
    }

    var runtimeFieldRef     = rtInstrs[0].Operand;              // ldsfld _Runtime (instr 0)
    var runtimeInitFieldRef = rtInstrs[brFalseIdx - 1].Operand; // ldsfld _RuntimeInit
    var initBlockStart      = rtInstrs[ldNullIdx + 3];          // instruction after leave.s

    // Change ldnull → ldsfld _Runtime (so return path returns actual value)
    rtInstrs[ldNullIdx].OpCode  = CilOpCodes.Ldsfld;
    rtInstrs[ldNullIdx].Operand = runtimeFieldRef;

    // Insert 5 instructions before the (modified) ldNullIdx:
    //   [A] ldsfld _Runtime
    //   [B] brtrue.s → ldsfld _Runtime (non-null: return it)
    //   [C] ldc.i4.0
    //   [D] stsfld _RuntimeInit   ← reset to allow retry
    //   [E] br.s → initBlockStart ← retry init
    var insA = new CilInstruction(CilOpCodes.Ldsfld, runtimeFieldRef);
    var insB = new CilInstruction(CilOpCodes.Brtrue_S, rtInstrs[ldNullIdx].CreateLabel());
    var insC = new CilInstruction(CilOpCodes.Ldc_I4_0);
    var insD = new CilInstruction(CilOpCodes.Stsfld, runtimeInitFieldRef);
    var insE = new CilInstruction(CilOpCodes.Br_S, initBlockStart.CreateLabel());

    rtInstrs.Insert(ldNullIdx, insE);
    rtInstrs.Insert(ldNullIdx, insD);
    rtInstrs.Insert(ldNullIdx, insC);
    rtInstrs.Insert(ldNullIdx, insB);
    rtInstrs.Insert(ldNullIdx, insA);

    Console.WriteLine("Part 3 done: get_Runtime will retry if _Runtime==null.");

    // ── Part 4: DISABLED — pthread_jit_write_protect_np causes SIGBUS ─────────────
    // Calling pthread_jit_write_protect_np from managed JIT code switches the current
    // thread to write mode for MAP_JIT pages, but the `ret` instruction returning from
    // the managed P/Invoke is ALSO in a MAP_JIT page — executing it crashes (SIGBUS).
    // The correct fix is Part 1b (loading DetourNativeMonoPlatform via full path),
    // which uses mono_mprotect internally and handles W^X correctly from native code.
    Console.WriteLine("Part 4: SKIPPED (pthread_jit_write_protect_np causes SIGBUS from managed code).");
    goto skipPart4;

    // On Apple Silicon (macOS arm64), mprotect(PROT_RWX) always fails with EACCES
    // because JIT pages use hardware W^X enforcement. MAP_JIT pages must be toggled
    // via pthread_jit_write_protect_np(0) to enable writing and (1) to re-enable exec.
    // Patch MakeWritable to call pthread_jit_write_protect_np(0) on macOS,
    // and MakeExecutable/MakeReadWriteExecutable to call it with (1)/(0) respectively.

    var posixType = allTypes.FirstOrDefault(t => t.Name == "DetourNativeMonoPosixPlatform");
    if (posixType == null)
    { Console.Error.WriteLine("DetourNativeMonoPosixPlatform not found, skipping Part 4"); goto writePart2; }

    // Add P/Invoke method for pthread_jit_write_protect_np
    var jitProtectMethod = posixType.Methods.FirstOrDefault(m => m.Name == "pthread_jit_write_protect_np");
    if (jitProtectMethod == null)
    {
        jitProtectMethod = new MethodDefinition(
            "pthread_jit_write_protect_np",
            AsmResolver.PE.DotNet.Metadata.Tables.Rows.MethodAttributes.Private |
            AsmResolver.PE.DotNet.Metadata.Tables.Rows.MethodAttributes.Static |
            AsmResolver.PE.DotNet.Metadata.Tables.Rows.MethodAttributes.PInvokeImpl |
            AsmResolver.PE.DotNet.Metadata.Tables.Rows.MethodAttributes.HideBySig,
            MethodSignature.CreateStatic(
                module.CorLibTypeFactory.Void,
                module.CorLibTypeFactory.Int32));
        jitProtectMethod.ImplAttributes =
            AsmResolver.PE.DotNet.Metadata.Tables.Rows.MethodImplAttributes.PreserveSig;
        var libSysRef = module.DefaultImporter.ImportModule(new ModuleReference("libSystem.B.dylib"));
        jitProtectMethod.ImplementationMap = new ImplementationMap(
            libSysRef,
            "pthread_jit_write_protect_np",
            ImplementationMapAttributes.CallConvCdecl);
        posixType.Methods.Add(jitProtectMethod);
        Console.WriteLine("Part 4: Added pthread_jit_write_protect_np P/Invoke.");
    }

    // Find PlatformHelper::Is method reference (already referenced in get_Native)
    // Find PlatformHelper::Is reference from get_Native (already imported)
    IMethodDescriptor? platformHelperIs = null;
    var getNativeMethod = detourHelper.Methods.FirstOrDefault(m => m.Name == "get_Native");
    if (getNativeMethod?.CilMethodBody != null)
        foreach (var instr in getNativeMethod.CilMethodBody.Instructions)
            if ((instr.OpCode == CilOpCodes.Call || instr.OpCode == CilOpCodes.Callvirt)
                && instr.Operand?.ToString()?.Contains("Is") == true
                && instr.Operand?.ToString()?.Contains("PlatformHelper") == true)
            { platformHelperIs = instr.Operand as IMethodDescriptor; break; }

    if (platformHelperIs == null)
    { Console.Error.WriteLine("PlatformHelper::Is not found, skipping Part 4"); goto writePart2; }

    // Platform.MacOS value from IL = 73 (ldc.i4.s 73 in get_Native)
    const int PlatformMacOS = 73;

    // Patch MakeWritable: on macOS, call pthread_jit_write_protect_np(0); else call original
    void PatchMemProtectMethod(string methodName, int arg) {
        var m = posixType.Methods.FirstOrDefault(mx => mx.Name == methodName && mx.CilMethodBody != null);
        if (m == null) { Console.Error.WriteLine($"  {methodName} not found"); return; }
        var body = m.CilMethodBody!;
        var mi = body.Instructions;

        // Already patched?
        if (mi.Any(i => i.OpCode == CilOpCodes.Ldc_I4_S && i.Operand is sbyte sb && sb == (sbyte)PlatformMacOS)
         || mi.Any(i => i.OpCode == CilOpCodes.Ldc_I4 && i.Operand is int iv && iv == PlatformMacOS))
        { Console.WriteLine($"  {methodName} already patched"); return; }

        var originalRet = mi.Last(i2 => i2.OpCode == CilOpCodes.Ret);

        // Prepend: check if macOS; if yes, call pthread_jit_write_protect_np(arg); ret
        //          else fall through to original code
        var originalFirst = mi[0];
        var checkArm = new CilInstruction(CilOpCodes.Ldc_I4_S, (sbyte)PlatformMacOS);
        var callIs   = new CilInstruction(CilOpCodes.Call, (IMethodDescriptor)platformHelperIs);
        var brFalse  = new CilInstruction(CilOpCodes.Brfalse, originalFirst.CreateLabel());
        var ldArg    = new CilInstruction(arg == 0 ? CilOpCodes.Ldc_I4_0 : CilOpCodes.Ldc_I4_1);
        var callJit  = new CilInstruction(CilOpCodes.Call, module.DefaultImporter.ImportMethod(jitProtectMethod));
        var earlyRet = new CilInstruction(CilOpCodes.Ret);

        mi.Insert(0, earlyRet);
        mi.Insert(0, callJit);
        mi.Insert(0, ldArg);
        mi.Insert(0, brFalse);
        mi.Insert(0, callIs);
        mi.Insert(0, checkArm);

        // Upgrade short branches
        foreach (var ci2 in mi) {
            if (ci2.OpCode == CilOpCodes.Brfalse_S) ci2.OpCode = CilOpCodes.Brfalse;
            if (ci2.OpCode == CilOpCodes.Brtrue_S)  ci2.OpCode = CilOpCodes.Brtrue;
        }

        Console.WriteLine($"  Patched {methodName}: macOS arm64 will use pthread_jit_write_protect_np({arg}).");
    }

    PatchMemProtectMethod("MakeWritable",          0); // disable write protection
    PatchMemProtectMethod("MakeExecutable",        1); // re-enable execute protection
    PatchMemProtectMethod("MakeReadWriteExecutable", 0); // disable write protection

    Console.WriteLine("Part 4 done.");

    skipPart4:

    // ── Part 5: Make DetourHelper.GetIdentifiable null-safe ────────────────────────
    // On macOS arm64, get_Runtime() can return null if the DetourRuntimeILPlatform
    // selftest fails. The original code does:
    //   get_Runtime().GetIdentifiable(method) → NRE when Runtime is null
    //
    // Fix: insert a null check before the callvirt. If get_Runtime() returns null,
    // return the method argument unchanged. This uses a simple branch instead of
    // try-catch, avoiding Mono exception handler quirks.
    //
    // Original:
    //   000: call get_Runtime()
    //   001: ldarg.0
    //   002: callvirt GetIdentifiable
    //   003: ret
    //
    // Patched:
    //   000: call get_Runtime()
    //   001: dup
    //   002: brtrue.s [ok]
    //   003: pop              ← drop null runtime ref
    //   004: ldarg.0          ← return method unchanged
    //   005: ret
    //   [ok]:
    //   006: ldarg.0
    //   007: callvirt GetIdentifiable
    //   008: ret

    var getIdentifiable = detourHelper.Methods.FirstOrDefault(m =>
        m.Name == "GetIdentifiable" && m.CilMethodBody != null && m.Parameters.Count == 1);

    if (getIdentifiable == null)
    { Console.Error.WriteLine("DetourHelper.GetIdentifiable not found, skipping Part 5"); goto writePart2; }

    {
        var gi = getIdentifiable.CilMethodBody.Instructions;

        // Check if already patched (look for dup after call get_Runtime)
        if (gi.Count > 1 && gi[1].OpCode == CilOpCodes.Dup)
        { Console.WriteLine("Part 5: GetIdentifiable already patched, skipping."); goto writePart2; }

        // Original: [0]=call get_Runtime, [1]=ldarg.0, [2]=callvirt GetIdentifiable, [3]=ret
        // We need to insert dup + brtrue + pop + ldarg.0 + ret after the call
        var okLabel = gi[1]; // original ldarg.0 — will become the branch target

        gi.Insert(1, new CilInstruction(CilOpCodes.Dup));
        gi.Insert(2, new CilInstruction(CilOpCodes.Brtrue_S, okLabel.CreateLabel()));
        gi.Insert(3, new CilInstruction(CilOpCodes.Pop));
        gi.Insert(4, new CilInstruction(CilOpCodes.Ldarg_0));
        gi.Insert(5, new CilInstruction(CilOpCodes.Ret));

        Console.WriteLine("Part 5: DetourHelper.GetIdentifiable is now null-safe (branch, no try-catch).");
    }

    writePart2:
    string tmp2 = dllPath + ".patched";
    module.Write(tmp2);
    File.Move(tmp2, dllPath, overwrite: true);
    Console.WriteLine($"\nDone. All parts applied to MonoMod.RuntimeDetour.dll.");
    return 0;
}


// ── patch-monomod ──────────────────────────────────────────────────────────────
static int PatchMonoMod_OLD(string dllPath_UNUSED) => 0; // replaced below

static int PatchMonoMod(string dllPath)
{
    // Strategy: patch PlatformHelper.DeterminePlatform() in the ORIGINAL MonoMod.Utils.dll.
    //
    // The original DLL uses Module.GetPEKind() to detect ARM, checking only machine type
    // 0x01C4 (ARM 32-bit). We add a check for 0xAA64 (ARM64 = 43620) in parallel.
    //
    // Original IL at the ARM check:
    //   ldloc.s V_7        (ImageFileMachine value)
    //   ldc.i4 452         (0x01C4 = ARM32)
    //   bne.un.s [ret]     if NOT ARM32, skip to ret
    //   ldsfld _current
    //   ldc.i4 65536       Platform.ARM
    //   or
    //   stsfld _current    _current |= Platform.ARM
    //   ret
    //
    // Patched IL:
    //   ldloc.s V_7
    //   ldc.i4 452         ARM32
    //   bne.un.s [check_arm64]   if NOT ARM32, check ARM64 instead
    //   ldsfld _current
    //   ldc.i4 65536
    //   or
    //   stsfld _current
    //   br.s [ret]         ARM32 match done, skip ARM64 check
    // [check_arm64]:
    //   ldloc.s V_7
    //   ldc.i4 43620       0xAA64 = ARM64
    //   bne.un.s [ret]     if NOT ARM64, skip
    //   ldsfld _current
    //   ldc.i4 65536
    //   or
    //   stsfld _current
    // [ret]:
    //   ret
    if (!File.Exists(dllPath)) { Console.Error.WriteLine($"Not found: {dllPath}"); return 1; }
    string bak = dllPath + ".orig";
    if (!File.Exists(bak)) { File.Copy(dllPath, bak); Console.WriteLine($"Backup: {bak}"); }

    var module = ModuleDefinition.FromFile(dllPath);
    var ph = module.TopLevelTypes.FirstOrDefault(t => t.Name == "PlatformHelper");
    if (ph == null) { Console.Error.WriteLine("PlatformHelper not found"); return 1; }

    var det = ph.Methods.FirstOrDefault(m => m.Name == "DeterminePlatform");
    if (det?.CilMethodBody == null) { Console.Error.WriteLine("DeterminePlatform not found"); return 1; }

    var instrs = det.CilMethodBody.Instructions;
    Console.WriteLine($"DeterminePlatform: {instrs.Count} instructions");

    // Find the pattern: ldc.i4 452 (0x01C4 ARM32) followed by bne.un.s
    // This is the PE kind ARM detection in the #else (net452) code path.
    const int ARM32MachineType = 452;   // 0x01C4
    const int ARM64MachineType = 43620; // 0xAA64

    int arm32CheckIdx = -1;
    for (int i = 0; i < instrs.Count - 1; i++)
    {
        bool isArm32Const = (instrs[i].OpCode == CilOpCodes.Ldc_I4 && instrs[i].Operand is int v32 && v32 == ARM32MachineType);
        bool nextIsBne = instrs[i + 1].OpCode == CilOpCodes.Bne_Un_S || instrs[i + 1].OpCode == CilOpCodes.Bne_Un;
        if (isArm32Const && nextIsBne) { arm32CheckIdx = i; break; }
    }

    if (arm32CheckIdx < 0)
    {
        Console.Error.WriteLine("ARM32 machine type check (ldc.i4 452 + bne.un.s) not found.");
        Console.Error.WriteLine("Run 'inspect' to see the IL.");
        return 1;
    }

    Console.WriteLine($"Found ARM32 check at instr {arm32CheckIdx}");
    for (int k = Math.Max(0, arm32CheckIdx - 2); k < Math.Min(instrs.Count, arm32CheckIdx + 9); k++)
        Console.WriteLine($"  {k:000}: {instrs[k]}");

    // bneInstr = instrs[arm32CheckIdx + 1], currently jumps to ret
    var bneInstr = instrs[arm32CheckIdx + 1];
    var retInstr = instrs.Last(); // the ret at method end

    // The ARM-setting block is at arm32CheckIdx + 2 .. arm32CheckIdx + 5:
    //   ldsfld _current, ldc.i4 65536, or, stsfld _current
    // Then the ret follows.
    // We need ldloc for the ImageFileMachine local (one instruction before arm32CheckIdx):
    var ldlocInstr = instrs[arm32CheckIdx - 1]; // ldloc.s V_7

    // Create the ARM64 check instructions
    var arm64Ldloc   = new CilInstruction(ldlocInstr.OpCode, ldlocInstr.Operand);
    var arm64Ldc     = new CilInstruction(CilOpCodes.Ldc_I4, ARM64MachineType);
    var arm64BneRet  = new CilInstruction(CilOpCodes.Bne_Un_S, retInstr.CreateLabel());
    // Clone the ARM-flag-setting instructions
    var armSet1 = new CilInstruction(instrs[arm32CheckIdx + 2].OpCode, instrs[arm32CheckIdx + 2].Operand);
    var armSet2 = new CilInstruction(instrs[arm32CheckIdx + 3].OpCode, instrs[arm32CheckIdx + 3].Operand);
    var armSet3 = new CilInstruction(instrs[arm32CheckIdx + 4].OpCode, instrs[arm32CheckIdx + 4].Operand);
    var armSet4 = new CilInstruction(instrs[arm32CheckIdx + 5].OpCode, instrs[arm32CheckIdx + 5].Operand);

    // Insert before ret:
    //   br.s [ret]         — ARM32 path falls through to here, skips ARM64 check
    //   [arm64 check start]:
    //   ldloc.s V_7
    //   ldc.i4 43620
    //   bne.un.s [ret]     — not ARM64, skip
    //   [ARM flag setting]
    int insertAt = instrs.Count - 1; // before ret

    var brSkip = new CilInstruction(CilOpCodes.Br_S, retInstr.CreateLabel());
    instrs.Insert(insertAt++, brSkip);
    instrs.Insert(insertAt++, arm64Ldloc);
    instrs.Insert(insertAt++, arm64Ldc);
    instrs.Insert(insertAt++, arm64BneRet);
    instrs.Insert(insertAt++, armSet1);
    instrs.Insert(insertAt++, armSet2);
    instrs.Insert(insertAt++, armSet3);
    instrs.Insert(insertAt,   armSet4);

    // Redirect bneInstr to point to arm64Ldloc (the start of our ARM64 check)
    bneInstr.Operand = arm64Ldloc.CreateLabel();

    string tmp = dllPath + ".patched";
    module.Write(tmp);
    File.Move(tmp, dllPath, overwrite: true);
    Console.WriteLine("\nDone. PlatformHelper.DeterminePlatform() now detects ARM64 (0xAA64) in addition to ARM32 (0x01C4).");
    Console.WriteLine("DetourNativeARMPlatform will be selected on Apple Silicon, enabling Harmony patches.");
    return 0;
}
