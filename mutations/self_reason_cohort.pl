# Explain a cohort refusal of enrol_self as nothing in particular.
s/(        if \(\$cap > 0 && \$taken >= \$cap\) \{\n            return self::BLOCKED_FULL;\n        \}\n)        if \(!\$incohort\) \{\n            return self::BLOCKED_COHORT;\n        \}\n/$1/;
