# Keep the next action across a reset: a test or a request that changed the enrolments reads the old answer.
s/        self::\$nextaction = \[\];\n//;
