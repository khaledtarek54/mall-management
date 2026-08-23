#!/usr/bin/env python3
"""Mutation-audit the conformance gates: does each gate actually notice the defect it names?"""
import json, shutil, subprocess, sys, os

REPO = "/Users/khaled/Herd/mall-management"
os.chdir(REPO)
ENV = dict(os.environ)
ENV["PATH"] = "/tmp/phpbin:" + ENV.get("PATH", "")
ENV["PHP_INI_SCAN_DIR"] = ":/tmp/phpini-atriom"

def run_gate(test_path):
    r = subprocess.run(["vendor/bin/pest", test_path],
                       capture_output=True, text=True, env=ENV, timeout=900)
    out = r.stdout + r.stderr
    if '"result":"passed"' in out:
        return "passed", out
    if '"result":"failed"' in out:
        return "failed", out
    return "error", out

def audit(name, gate, path, old, new):
    """Apply one mutation, run its gate, restore. Returns a result dict."""
    if not os.path.exists(path):
        return {"gate": name, "verdict": "TARGET MISSING", "detail": path}
    src = open(path, encoding="utf-8").read()
    n = src.count(old)
    if n != 1:
        return {"gate": name, "verdict": "AMBIGUOUS TARGET", "detail": f"{n} matches for the anchor in {path}"}

    bak = src
    try:
        open(path, "w", encoding="utf-8").write(src.replace(old, new))
        # The mutation MUST have landed — a silent no-op reports a false PASS, which has
        # happened twice in this project.
        if open(path, encoding="utf-8").read() == bak:
            return {"gate": name, "verdict": "MUTATION DID NOT LAND", "detail": path}
        status, out = run_gate(gate)
    finally:
        open(path, "w", encoding="utf-8").write(bak)

    verdict = "CAUGHT" if status == "failed" else ("HOLE" if status == "passed" else "INCONCLUSIVE")
    return {"gate": name, "verdict": verdict, "status": status,
            "detail": "" if verdict == "CAUGHT" else out[-400:]}

if __name__ == "__main__":
    muts = json.load(open(sys.argv[1]))
    results = []
    for m in muts:
        r = audit(m["name"], m["gate"], m["path"], m["old"], m["new"])
        results.append(r)
        print(f"{r['verdict']:<22} {r['gate']}", flush=True)
        if r["verdict"] not in ("CAUGHT",):
            print("    " + r.get("detail", "").replace("\n", "\n    ")[:600], flush=True)
    json.dump(results, open(sys.argv[2], "w"), indent=2)
    holes = [r for r in results if r["verdict"] != "CAUGHT"]
    print(f"\n=== {len(results) - len(holes)}/{len(results)} gates caught their own defect ===")
