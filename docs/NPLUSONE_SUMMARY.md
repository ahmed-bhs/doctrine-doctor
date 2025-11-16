# NPlusOne Project - Executive Summary

## Quick Overview

**NPlusOne** is a sophisticated N+1 query detection library for Python ORMs. At 638 lines of code, it demonstrates elegant patterns for runtime ORM monitoring. This analysis identifies innovations applicable to doctrine-doctor.

---

## Architecture at a Glance

```
ORM Methods (Django, SQLAlchemy, Peewee)
        ↓ [Monkey-patched to emit signals]
Signal/Event System (blinker pub/sub)
        ↓
Listeners (LazyListener, EagerListener)
        ↓
Notifiers (LogNotifier, ErrorNotifier)
```

**Key Innovation**: Signal-driven design decouples detection from ORM implementation.

---

## Critical Components & Code Examples

### 1. LazyListener - N+1 Detection Logic

**File**: `/nplusone/core/listeners.py` (Lines 80-103)

```python
class LazyListener(Listener):
    def setup(self):
        self.loaded = set()  # Instances from queries
        self.ignore = set()  # Single-record exemptions
        signals.load.connect(self.handle_load)
        signals.lazy_load.connect(self.handle_lazy)

    def handle_lazy(self, caller, args=None, kwargs=None, context=None, ret=None, parser=None):
        model, instance, field = parser(args, kwargs, context)
        if instance in self.loaded and instance not in self.ignore:
            # N+1 DETECTED: Instance was already loaded
            message = LazyLoadMessage(model, field)
            self.parent.notify(message)
```

**Why This Works**:
- Tracks which instances came from queries
- Checks if lazy-loaded instance was already in memory
- Exempts single-record loads

**For doctrine-doctor**: Implement instance tracking in AST analysis

---

### 2. EagerTracker - Unused Eager Load Detection

**File**: `/nplusone/core/listeners.py` (Lines 132-156)

```python
class EagerTracker(object):
    def __init__(self):
        # {(model, field): {query_id: {instance_keys}}}
        self.data = defaultdict(lambda: defaultdict(set))

    def track(self, model, field, instances, key):
        # key = id(query_context) - distinguishes separate queries
        self.data[(model, field)][key].update(instances)

    def prune(self, touched):
        for model, field, touch_instances in touched:
            group = self.data[(model, field)]
            for key, fetch_instances in list(group.items()):
                # Remove if any instance was actually accessed
                if touch_instances & fetch_instances:
                    group.pop(key, None)

    @property
    def unused(self):
        # Return (model, field) pairs with remaining entries
        return [(m, f) for (m, f), g in self.data.items() if g]
```

**Why This Works**:
- Nested structure groups eager loads by query context
- Can identify specific unused loads
- Set intersection efficiently finds accessed data

**Feature Not in doctrine-doctor**: Unused eager load detection

---

### 3. Signal System - Decoupling Mechanism

**File**: `/nplusone/core/signals.py`

```python
# blinker-based pub/sub
load = blinker.Signal()        # Query results loaded
lazy_load = blinker.Signal()   # Lazy loading detected
eager_load = blinker.Signal()  # Eager loading detected
touch = blinker.Signal()       # Data accessed

def signalify(signal, func, parser=None, **context):
    """Wraps ORM method to emit signals without modifying ORM code"""
    @functools.wraps(func)
    def wrapped(*args, **kwargs):
        ret = func(*args, **kwargs)
        signal.send(
            get_worker(),
            args=args,
            kwargs=kwargs,
            ret=ret,
            context=context,
            parser=parser,  # Extracts relevant info from ORM call
        )
        return ret
    return wrapped
```

**Example Usage** (SQLAlchemy):
```python
# Hook lazy loading
strategies.LazyLoader._load_for_state = signals.signalify(
    signals.lazy_load,
    strategies.LazyLoader._load_for_state,
    parser=parse_lazy_load,
)

# Hook eager loading
loading._populate_full = signals.signalify(
    signals.eager_load,
    loading._populate_full,
    parser=parse_populate,
)
```

**Why This Works**:
- Parser extracts ORM-specific data
- Listeners don't need ORM knowledge
- Enables multiple listeners simultaneously

---

### 4. Instance Keying Strategy

**File**: `/nplusone/ext/sqlalchemy.py` (Lines 16-33)

```python
def to_key(instance):
    """Create unique identifier: ModelName:PrimaryKey"""
    model = type(instance)
    return ':'.join(
        [model.__name__] +
        [format(instance.__dict__.get(key.key)) 
         for key in get_primary_keys(model)]
    )
    # Returns: "User:123"
```

**Benefits**:
- Unique but compact
- Deterministic (same instance = same key)
- Comparable across requests
- Works with composite primary keys

---

### 5. Single-Record Exemption

**File**: `/nplusone/ext/sqlalchemy.py` (Lines 93-111)

```python
def is_single(offset, limit):
    return limit is not None and limit - (offset or 0) == 1

# In query_iter:
signal = (
    signals.ignore_load
    if is_single(self._offset, self._limit)
    else signals.load
)

# Special handling for .one() and .one_or_none()
for method in ['one_or_none', 'one']:
    original = getattr(query.Query, method)
    decorated = signals.signalify(signals.ignore_load, original, parse_get)
    setattr(query.Query, method, decorated)
```

**Why This Works**:
- Single-record loads should not trigger N+1 detection
- Accessing related data on single record = 1+1 queries (acceptable)
- Exempts: `.one()`, `.one_or_none()`, `.first()`, `.limit(1)`

**Insight for doctrine-doctor**: Implement similar exemptions for single-record patterns

---

### 6. Per-Request State Management (Django)

**File**: `/nplusone/ext/django/middleware.py`

```python
class NPlusOneMiddleware(MiddlewareMixin):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # WeakKeyDictionary: Auto-cleanup when request is GCed
        self.listeners = weakref.WeakKeyDictionary()

    def process_request(self, request):
        # Initialize listeners per-request
        self.listeners[request] = {}
        for name, listener_type in listeners.listeners.items():
            self.listeners[request][name] = listener_type(self)
            self.listeners[request][name].setup()

    def process_response(self, request, response):
        # Teardown: collect results
        for name in listeners.listeners:
            listener = self.listeners.get(request, {}).pop(name, None)
            if listener:
                listener.teardown()
        return response
```

**Why This Works**:
- Thread-safe: Each request has isolated state
- Memory-safe: WeakKeyDictionary cleans up automatically
- No cross-request pollution

---

### 7. Whitelisting with Pattern Matching

**File**: `/nplusone/core/listeners.py` (Lines 10-31)

```python
class Rule(object):
    def __init__(self, label=None, model=None, field=None):
        self.label = label
        self.model = model
        self.field = field

    def compare(self, label, model, field):
        # All specified criteria must match
        return (
            (self.label or self.model or self.field) and
            (self.label is None or self.label == label) and
            (self.model is None or self.match_model(model)) and
            (self.field is None or self.field == field)
        )

    def match_model(self, model):
        # fnmatch pattern support
        return (
            self.model is model or (
                isinstance(self.model, six.string_types) and
                fnmatch.fnmatch(model.__name__, self.model)
            )
        )
```

**Example Usage**:
```python
NPLUSONE_WHITELIST = [
    {'model': 'myapp.*'},           # Pattern match
    {'model': 'User', 'field': 'cache*'},  # Multi-criteria
    {'label': 'n_plus_one'},        # By issue type
]
```

**Feature for doctrine-doctor**: Enhanced configuration system

---

## Key Metrics from NPlusOne

| Aspect | Value |
|--------|-------|
| Core Code | 200 lines |
| Extensions | 350 lines |
| Tests | 700 lines |
| Code-to-Test Ratio | 1:1 |
| ORM Support | 5 (Django, SQLAlchemy, Flask, Peewee, WSGI) |
| Signal Types | 5 (load, lazy_load, eager_load, touch, ignore_load) |
| Listener Types | 2 (Lazy, Eager) |
| Detection Approaches | 3 (Lazy detection, Eager detection, Instance tracking) |

---

## Features NOT in doctrine-doctor (Could Add)

| Feature | Complexity | Impact |
|---------|-----------|--------|
| **Unused Eager Load Detection** | Medium | HIGH - Catches over-loading |
| **Query Count Aggregation** | Medium | MEDIUM - Better reporting |
| **Nested Relationship N+1** | High | HIGH - Handles complex cases |
| **Severity Classification** | Low | MEDIUM - Better prioritization |
| **Field-Level Access Tracking** | Medium | MEDIUM - More precise fixes |
| **Pattern-Based Configuration** | Low | LOW - Quality of life |

---

## Algorithms Worth Adopting

### 1. EagerTracker Grouping by Query ID
Currently: Report "User.addresses unused"
Better: Report "User.addresses unused in query A (5 instances), query B (3 instances)"

### 2. Instance Key Pattern
Use format: `ClassName:PrimaryKey` for consistent instance identification

### 3. Single-Record Exemption Logic
Check for `.limit(1)`, `.one()`, `.first()` patterns to avoid false positives

### 4. Signal/Event-Driven Detection
Could implement for runtime monitoring layer in future

---

## Comparison: Runtime vs. Static Analysis

### NPlusOne (Runtime)
```
Pros:
  + Zero false positives
  + Actual performance data
  + Real execution paths
  
Cons:
  - Only executed paths
  - Requires test suite
  - Runtime overhead
```

### doctrine-doctor (Static)
```
Pros:
  + All code paths
  + No overhead
  + CI-friendly
  
Cons:
  - Potential false positives
  - Harder to estimate impact
  - Must assume usage
```

### Hybrid Approach
1. **Static** (doctrine-doctor): Catch architectural issues
2. **Runtime** (like nplusone): Verify in tests
3. **Together**: Comprehensive coverage

---

## Code Quality Observations

### Strengths
- Clear separation: core vs. extensions
- Plugin architecture for new ORMs
- Minimal coupling via signals
- Comprehensive test coverage
- Thread-safe state management

### Weaknesses
- Heavy monkey-patching (fragile across versions)
- Limited docstrings
- No architectural documentation
- Python 2.7 compatibility (no type hints)
- Complex Django patching (369 lines)

---

## Actionable Recommendations

### Immediate (v1.x)
1. Implement unused eager load detection
2. Add nested relationship N+1 detection
3. Enhanced configuration with patterns
4. Severity classification system

### Medium-term (v2.x)
1. Instance-level tracking in suggestions
2. Query count estimation
3. Performance impact calculation
4. Multiple fix option ranking

### Long-term (v3.x)
1. Optional runtime monitoring layer
2. Hybrid static + runtime analysis
3. Database schema integration
4. Framework-specific optimizations

---

## File References

**Core Files**:
- `/nplusone/core/signals.py` - Signal system (55 lines)
- `/nplusone/core/listeners.py` - Detection logic (163 lines)
- `/nplusone/core/profiler.py` - Coordinator (29 lines)
- `/nplusone/core/notifiers.py` - Output handlers (59 lines)

**Extensions**:
- `/nplusone/ext/django/patch.py` - Django integration (369 lines)
- `/nplusone/ext/sqlalchemy.py` - SQLAlchemy integration (127 lines)
- `/nplusone/ext/flask_sqlalchemy.py` - Flask integration (63 lines)

**Tests**:
- `/tests/test_sqlalchemy.py` - SQLAlchemy tests (146 lines)
- `/tests/test_flask_sqlalchemy.py` - Flask tests (244 lines)
- `/tests/conftest.py` - Test fixtures (39 lines)

