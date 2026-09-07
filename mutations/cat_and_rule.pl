# Turn the AND over every effectively-unlisted ancestor into an "any of them":
# eligibility for the inner category then unlocks the outer one it sits in.
s/\$answer = !in_array\(false, \$terms, true\);/\$answer = in_array(true, \$terms, true);/;
