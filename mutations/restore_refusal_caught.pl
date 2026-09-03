# Stop catching the capability refusal: a restore by somebody who may not publish
# then fails outright instead of logging and keeping the target's state.
s/catch \(\\required_capability_exception \$e\)/catch (\\dml_exception \$e)/;
