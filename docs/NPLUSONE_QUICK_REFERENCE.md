# NPlusOne Quick Reference Guide

## One-Sentence Summary
NPlusOne uses signal-driven event listeners to detect N+1 queries at runtime by tracking which instances are loaded vs. accessed.

---

## Architecture Pattern

**Signal-Driven Event Listener Pattern**:
1. ORM methods emit signals when called
2. Listeners subscribe to specific signals
3. Listeners maintain state (which instances were loaded/accessed)
4. At request end, listeners report issues

---

## 5 Key Signals

```python
signals.load           # Query loaded N records
signals.lazy_load      # Relationship accessed lazily (potential N+1)
signals.eager_load     # Relationship eagerly loaded
signals.touch          # Accessed field on instance
signals.ignore_load    # Single-record load (exempt from N+1)
```

---

## 3 Detection Mechanisms

### 1. LazyListener (Detects N+1)
```python
loaded = set()  # Track instances from queries
ignore = set()  # Track single-record loads

# When lazy_load signal fires:
if instance in loaded and instance not in ignore:
    report_n_plus_one()
```

### 2. EagerListener (Detects Unused Eager Loads)
```python
tracker = EagerTracker()  # {(model, field): {query_id: {instances}}}

# Track eager loads
tracker.track(model, field, instances, query_id)

# Prune accessed ones
tracker.prune(touched_instances)

# Report remaining
for model, field in tracker.unused:
    report_unused_eager_load()
```

### 3. Instance Keying
```python
key = f"{ClassName}:{PrimaryKey}"  # "User:123"
```

---

## Smart Exemptions

Doesn't report N+1 for:
- `.one()` - Single explicit record
- `.one_or_none()` - At most one record
- `.first()` - Single record expected
- `.limit(1)` - Single record constraint
- `.get(id)` - Direct lookup

**Why**: Accessing related data on single record = 1+1 queries (acceptable)

---

## Configuration

```python
# Django
NPLUSONE_LOGGER = logging.getLogger('nplusone')
NPLUSONE_LOG_LEVEL = logging.WARN
NPLUSONE_RAISE = True
NPLUSONE_WHITELIST = [
    {'model': 'myapp.*'},
    {'model': 'User', 'field': 'addresses'},
]

# Flask
app.config['NPLUSONE_RAISE'] = True
app.config['NPLUSONE_WHITELIST'] = [...]

# Generic
with profiler.Profiler(whitelist=[...]):
    # Code here
    pass
```

---

## Whitelisting Logic

**Rule Matching** (AND logic - all must match):
```python
{'label': 'n_plus_one'}  # Must be lazy_load type
{'model': 'User'}         # Exact model match
{'model': 'myapp.*'}      # fnmatch pattern
{'field': 'addresses'}    # Exact field match
{'field': 'cache*'}       # fnmatch pattern
```

---

## Key Code Locations

| Feature | File | Lines |
|---------|------|-------|
| N+1 Detection Logic | listeners.py | 80-103 |
| Unused Eager Detection | listeners.py | 132-156 |
| Signal System | signals.py | All |
| Django Integration | django/patch.py | All |
| Instance Keying | ext/sqlalchemy.py | 16-33 |

---

## Innovation: EagerTracker Structure

**Problem**: How to track which eager loads were never accessed?

**Solution**: Nested dict grouped by query context
```python
{
    (User, 'addresses'): {
        id(query1): {User:1, User:2},  # Loaded by query1
        id(query2): {User:3, User:4},  # Loaded by query2
    }
}

# When instances touched, prune entries
# Remaining entries = unused loads
```

**Benefit**: Can see which specific queries had unused loads

---

## What It Detects (✓) vs. Doesn't (✗)

| Issue | Detected |
|-------|----------|
| N+1 lazy loading in loop | ✓ |
| Unused eager loads | ✓ |
| Nested relationship N+1 | ✓ |
| Single-record lazy loads | ✗ |
| Dead code (unreached paths) | ✗ |
| All code paths | ✗ |

---

## Runtime vs. Static Analysis

**NPlusOne (Runtime)**:
- ✓ Zero false positives (real execution)
- ✓ Actual performance impact visible
- ✓ Only analyzed paths tested
- ✗ Requires running test suite
- ✗ Can't catch unreached code

**doctrine-doctor (Static)**:
- ✓ All code paths analyzed
- ✓ No runtime overhead
- ✓ Works in CI without tests
- ✗ Potential false positives
- ✗ Can't measure actual impact

---

## Best Practices from NPlusOne

1. **Use signals, not direct coupling**
   - Decouples detection from ORM
   - Enables multiple listeners
   - Allows suppression context

2. **Track instances, not just relationships**
   - Know which specific instances had lazy loads
   - Can distinguish between queries
   - Enables better reporting

3. **Exempt single-record loads**
   - Reduces false positives
   - Matches developer expectations
   - Reflects actual performance impact

4. **Per-request state in middleware**
   - Prevents cross-request pollution
   - Thread-safe without global state
   - Automatic cleanup

5. **Pattern-based whitelisting**
   - Flexible matching (exact, pattern)
   - Multi-criteria rules
   - No code changes needed

---

## Top 5 Features Missing from doctrine-doctor

1. **Unused Eager Load Detection**
   - NPlusOne detects over-loading
   - doctrine-doctor only detects under-loading
   - Medium effort, high impact

2. **Nested Relationship N+1**
   - Handle relationship chains
   - Follow associations through graph
   - High effort, high impact

3. **Severity Classification**
   - CRITICAL: High-traffic endpoints
   - HIGH: Normal endpoints
   - MEDIUM: Low-impact issues
   - Low effort, medium impact

4. **Query Count Aggregation**
   - Group by location/loop
   - Count extra queries
   - Medium effort, medium impact

5. **Field-Level Access Tracking**
   - Know which fields are accessed
   - More precise suggestions
   - Medium effort, low-medium impact

---

## Implementation Checklist

For implementing EagerTracker-style detection in doctrine-doctor:

- [ ] Define issue types (eager/lazy)
- [ ] Create nested tracking structure
- [ ] Implement set intersection pruning
- [ ] Add per-field tracking
- [ ] Integrate with existing analyzers
- [ ] Add configuration support
- [ ] Create test fixtures
- [ ] Document with examples

---

## Key Insights for Static Analysis

1. **Instance counting not possible** in static analysis
   - Can't know how many items in loop
   - Can estimate based on patterns
   - Should flag as "potential N+1"

2. **Single-record exemption** is important
   - `.limit(1)`, `.one()`, `find()` - safe
   - `for x in query:` - unsafe
   - Reduce false positives by detecting patterns

3. **Unused eager loads** easier to detect statically
   - If eager load exists but never accessed
   - No execution needed
   - Can report with confidence

4. **Nested relationships** require graph traversal
   - Follow $user->getAddresses()->getCountry()
   - Mark all as potentially accessed
   - Complex but high value

---

## Further Reading

See full analysis in:
- **NPLUSONE_ANALYSIS.md** - 1233 lines, comprehensive deep dive
- **NPLUSONE_SUMMARY.md** - Executive summary with code examples
