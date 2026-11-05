# Let the primer write the guest account's relationships: the memo is read before
# viewer_context(), so the guest then discovers what a caller handed over.
s/        if \(self::refuses_viewer\(\)\) \{\n            \/\/ The memo is read before viewer_context\(\), so a visitor or the guest account must never reach it.\n            return;\n        \}\n        \$viewer = \(int\) \$USER->id;\n        foreach \(array_merge/        \$viewer = (int) \$USER->id;\n        foreach (array_merge/;
